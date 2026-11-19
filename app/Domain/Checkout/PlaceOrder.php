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
use Lunar\Models\Country;
use Lunar\Models\Order;

/**
 * Turns the customer's cart into a placed order, with Lunar doing the work: the addresses and
 * the delivery option go on the cart, `createOrder()` builds the order (lines, addresses,
 * delivery line, VAT breakdown, and the vendor snapshot step in config/lunar/orders.php),
 * and the `bank-transfer` offline payment type places it, awaiting payment.
 *
 * Every customer is a vetted business buying for its employees, so `buying_for_employees`
 * is always true on the order (docs/data-contract.md, VAT): StoreTaxDriver applies the 20%
 * rate to zero-protective lines.
 */
final class PlaceOrder
{
    public const string PAYMENT_TYPE = 'bank-transfer';

    public function __construct(
        private readonly CartLineViews $views,
        private readonly DeliveryPolicy $delivery,
    ) {}

    /**
     * @param  array{full_name: string, email: string, phone: string, company_name: string, vat_number: ?string, po_number: ?string, notes: ?string, site_type: string, shipping: array<string, ?string>, billing: array<string, ?string>}  $details  Address arrays use line_one, line_two, city, county, postcode.
     *
     * @throws CheckoutRejected
     */
    public function __invoke(User $user, array $details): Order
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
                'payment_method' => self::PAYMENT_TYPE,
            ])]);

            $order = $cart->createOrder();

            $order->update([
                'customer_reference' => $details['po_number'],
                'notes' => $details['notes'],
            ]);

            $payment = Payments::driver(self::PAYMENT_TYPE)->cart($cart)->withData([])->authorize();
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
