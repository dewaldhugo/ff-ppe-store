<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use Lunar\Models\Cart;
use Lunar\Models\Order;

/**
 * Card payment, as the checkout needs it. The one implementation is StripeCardPayments
 * (lunarphp/stripe); tests bind a fake, so nothing talks to Stripe in the test suite.
 */
interface CardPayments
{
    /** Whether card payment is switched on: both Stripe keys are set. */
    public function isConfigured(): bool;

    /** The cart's total in Stripe's smallest unit (pence), for the card form's amount. */
    public function amount(Cart $cart): int;

    /**
     * The Stripe payment intent for this order, created or reused for the cart, with its amount set
     * to the order total in pence and the order reference stored on it.
     *
     * @return array{id: string, client_secret: string}
     *
     * @throws CheckoutRejected When a payment for this cart is already in progress or paid.
     */
    public function intentFor(Cart $cart, Order $order): array;

    /** The cart a payment intent was created for, or null when this store never made it. */
    public function cartForIntent(string $intentId): ?Cart;

    /**
     * Asks Stripe what became of the payment and, when it succeeded, places the order. Safe to call
     * more than once, and when the webhook got there first.
     */
    public function settle(Cart $cart, string $intentId): CardResult;
}
