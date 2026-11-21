<?php

declare(strict_types=1);

namespace App\Domain\Checkout;

use App\Domain\Cart\CartLineViews;
use App\Domain\Cart\Delivery\DeliveryPolicy;
use App\Models\User;
use Illuminate\Support\Str;
use Lunar\Exceptions\Carts\CartException;
use Lunar\Facades\CartSession;
use Lunar\Facades\Payments;
use Lunar\Models\Cart;
use Lunar\Models\Country;
use Lunar\Models\Order;

/**
 * Turns the customer's cart into an order, with Lunar doing the work: the addresses and the
 * delivery option go on the cart and `createOrder()` builds the order (lines, addresses, delivery
 * line, VAT breakdown, and the vendor snapshot step in config/lunar/orders.php). Then, by method
 * (PaymentMethods decides who may use which):
 *
 * - `account-30-day`: the offline payment type places it at once, awaiting payment (__invoke).
 * - `card`: the order stays a draft and a Stripe payment intent for its total is returned
 *   (startCard). The browser confirms the card with Stripe, and the order is placed only when
 *   Stripe reports the payment succeeded (CardPayments::settle, from the return page or the webhook).
 *
 * Every customer is a vetted business buying for its employees, so `buying_for_employees`
 * is always true on the order (docs/data-contract.md, VAT): StoreTaxDriver applies the 20%
 * rate to zero-protective lines.
 */
final class PlaceOrder
{
    public function __construct(
        private readonly CartLineViews $views,
        private readonly DeliveryPolicy $delivery,
        private readonly CardPayments $card,
    ) {}

    /**
     * Places an order on 30 day account terms.
     *
     * @param  array{payment_method: string, full_name: string, email: string, phone: string, company_name: string, vat_number: ?string, po_number: ?string, supplier_number?: ?string, notes: ?string, site_type: string, shipping: array<string, ?string>, billing: array<string, ?string>}  $details  Address arrays use line_one, line_two, city, county, postcode.
     *
     * @throws CheckoutRejected
     */
    public function __invoke(User $user, array $details): Order
    {
        $this->assertMayUse($user, $details['payment_method'], PaymentMethods::ACCOUNT);

        [$cart, $order] = $this->prepare($details);

        try {
            $payment = Payments::driver(PaymentMethods::ACCOUNT)->cart($cart)->withData([])->authorize();
        } catch (CartException $exception) {
            throw new CheckoutRejected($exception->getMessage(), previous: $exception);
        }

        if (! $payment?->success) {
            throw new CheckoutRejected('We could not place your order. Please try again.');
        }

        // The cart now belongs to the order: the next visit starts a fresh one.
        CartSession::forget(false);

        return $order->refresh();
    }

    /**
     * Prepares a card payment: the draft order and the Stripe payment intent for its total. The cart
     * is kept (and stays in the session) until the payment succeeds, so a failed card can be retried.
     *
     * @param  array{payment_method: string, full_name: string, email: string, phone: string, company_name: string, vat_number: ?string, po_number: ?string, supplier_number?: ?string, notes: ?string, site_type: string, shipping: array<string, ?string>, billing: array<string, ?string>}  $details
     * @return array{client_secret: string, return_url: string, order_reference: string}
     *
     * @throws CheckoutRejected
     */
    public function startCard(User $user, array $details): array
    {
        $this->assertMayUse($user, $details['payment_method'], PaymentMethods::CARD);

        [$cart, $order] = $this->prepare($details);

        $intent = $this->card->intentFor($cart, $order);

        return [
            'client_secret' => $intent['client_secret'],
            'return_url' => route('checkout.card-return'),
            'order_reference' => $order->reference,
        ];
    }

    /**
     * The customer's own terms decide, whatever the browser sent.
     *
     * @throws CheckoutRejected
     */
    private function assertMayUse(User $user, string $chosen, string $expected): void
    {
        if ($chosen !== $expected || ! PaymentMethods::canUse($user, $chosen)) {
            throw new CheckoutRejected('That payment method is not available on your account.');
        }
    }

    /**
     * Puts the addresses, delivery option and contact details on the cart and builds the order.
     *
     * @param  array<string, mixed>  $details
     * @return array{0: Cart, 1: Order}
     *
     * @throws CheckoutRejected
     */
    private function prepare(array $details): array
    {
        $cart = CartSession::current();

        if ($cart === null || $cart->lines->isEmpty()) {
            throw new CheckoutRejected('Your cart is empty.');
        }

        if (! $this->views->canCheckout($this->views->for($cart))) {
            throw new CheckoutRejected('Some items in your cart are no longer available in the quantity you chose. Please review your cart.');
        }

        try {
            $cart->setShippingAddress($this->address($details, $details['shipping'], withTax: false) + [
                'delivery_instructions' => $details['notes'],
                'meta' => ['site_type' => $details['site_type']],
            ]);
            $cart->setBillingAddress($this->address($details, $details['billing'], withTax: true));
            $cart->setShippingOption($this->delivery->option($cart->calculate()));

            $cart->update(['meta' => array_merge((array) $cart->meta, [
                'buying_for_employees' => true,
                'site_type' => $details['site_type'],
                'contact_name' => $details['full_name'],
                'contact_email' => $details['email'],
                'contact_phone' => $details['phone'],
                'payment_method' => $details['payment_method'],
            ])]);

            $order = $cart->createOrder();

            $order->update([
                'customer_reference' => $details['po_number'],
                'notes' => $details['notes'],
                // What the customer calls us in their own system, printed on the invoice.
                'meta' => array_merge((array) $order->meta, array_filter(['supplier_number' => $details['supplier_number'] ?? null])),
            ]);
        } catch (CartException $exception) {
            throw new CheckoutRejected($exception->getMessage(), previous: $exception);
        }

        return [$cart, $order];
    }

    /**
     * @param  array<string, ?string>  $fields
     * @return array<string, mixed>
     */
    private function address(array $details, array $fields, bool $withTax): array
    {
        $name = trim($details['full_name']);

        return [
            'country_id' => Country::query()->where('iso2', 'GB')->value('id'),
            'first_name' => Str::before($name, ' '),
            'last_name' => Str::contains($name, ' ') ? Str::after($name, ' ') : null,
            'company_name' => $details['company_name'],
            'tax_identifier' => $withTax ? $details['vat_number'] : null,
            'line_one' => $fields['line_one'],
            'line_two' => $fields['line_two'] ?: null,
            'city' => $fields['city'],
            'state' => $fields['county'] ?: null,
            'postcode' => $fields['postcode'],
            'contact_email' => $details['email'],
            'contact_phone' => $details['phone'],
        ];
    }
}
