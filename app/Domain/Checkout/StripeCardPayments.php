<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use Lunar\Facades\Payments;
use Lunar\Models\Cart;
use Lunar\Models\Order;
use Lunar\Stripe\Facades\Stripe;
use Lunar\Stripe\Managers\StripeManager;
use Lunar\Stripe\Models\StripePaymentIntent;
use Stripe\PaymentIntent;

/**
 * Card payment through lunarphp/stripe: a PaymentIntent per cart (card only), confirmed in the
 * browser with Stripe's Payment Element, then settled here and, as a backup, by the package's
 * webhook (/stripe/webhook, docs/cart.md). Card numbers never reach this server.
 */
final class StripeCardPayments implements CardPayments
{
    /** Intent states in which the amount can still be changed. */
    private const array OPEN = [
        PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
        PaymentIntent::STATUS_REQUIRES_ACTION,
    ];

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.key')) && filled(config('services.stripe.public_key'));
    }

    public function amount(Cart $cart): int
    {
        return StripeManager::toStripeAmount((int) ($cart->total ?? $cart->calculate()->total)->value, $cart->currency);
    }

    public function intentFor(Cart $cart, Order $order): array
    {
        $intent = Stripe::createIntent($cart->calculate());

        // Someone paying twice (a second tab, a back button) must not change a payment that is
        // already taken or being taken.
        if (! in_array($intent->status, self::OPEN, true)) {
            throw new CheckoutRejected('A payment for this order is already in progress or complete. Please check your orders or contact us before paying again.');
        }

        // The order is the truth: the intent is for exactly its total, which the package checks again
        // when the payment is settled. A retry after a changed cart or delivery address updates it.
        Stripe::updateIntentById($intent->id, [
            'amount' => StripeManager::toStripeAmount($order->total->value, $order->currency),
            'description' => 'Order '.$order->reference,
            'metadata' => ['order_reference' => $order->reference, 'cart_id' => (string) $cart->id],
        ]);

        return ['id' => $intent->id, 'client_secret' => (string) $intent->client_secret];
    }

    public function cartForIntent(string $intentId): ?Cart
    {
        return StripePaymentIntent::query()->where('intent_id', $intentId)->first()?->cart;
    }

    public function settle(Cart $cart, string $intentId): CardResult
    {
        // The webhook may already have placed it.
        $placed = Order::query()->where('cart_id', $cart->id)->whereNotNull('placed_at')->latest('id')->first();

        if ($placed !== null) {
            return CardResult::paid($placed);
        }

        $payment = Payments::driver('card')
            ->cart($cart->calculate())
            ->withData(['payment_intent' => $intentId])
            ->authorize();

        $order = Order::query()->where('cart_id', $cart->id)->latest('id')->first();

        if ($payment?->success && $order?->placed_at !== null) {
            return CardResult::paid($order);
        }

        $status = Stripe::fetchIntent($intentId)?->status;

        // Some cards clear a little later; the webhook places the order when they do.
        if (in_array($status, [PaymentIntent::STATUS_PROCESSING, PaymentIntent::STATUS_REQUIRES_CAPTURE], true)) {
            return CardResult::pending($order);
        }

        return CardResult::failed($payment?->message);
    }
}
