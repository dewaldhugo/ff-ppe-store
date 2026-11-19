<?php

declare(strict_types=1);

namespace App\Domain\Cart;

use App\Domain\Cart\Delivery\DeliveryPolicy;
use Lunar\DataTypes\Price;
use Lunar\Models\Cart;

/**
 * The money stack under a cart, shared by the cart page and the checkout: subtotal ex VAT,
 * discount, delivery ex VAT, total ex VAT, VAT, and the total inc VAT. Every figure is
 * Lunar's (line prices, the tax driver, the shipping option, discounts); this only lays them
 * out and, before an address is chosen, offers the delivery option so the stack is complete.
 */
final class CartSummary
{
    public function __construct(private readonly DeliveryPolicy $delivery) {}

    /**
     * Price the cart with the store's delivery on it. Used while no shipping address (and so
     * no chosen option) is saved on the cart yet; once one is, Lunar reads the option itself.
     */
    public function withDelivery(Cart $cart): Cart
    {
        $cart->shippingOptionOverride = $this->delivery->option($cart);

        return $cart->recalculate();
    }

    /**
     * `vatRows` is the VAT by rate: the goods (and delivery) at each rate, ex VAT, and the VAT on them.
     * Its VAT amounts add up to `vat`.
     *
     * @return array{subtotal: string, discount: ?string, delivery: ?string, deliveryFree: bool, totalExVat: string, vat: string, vatRows: list<array{label: string, net: string, vat: string, zeroRated: bool}>, total: string, freeFrom: string, deliveryCharge: string}
     */
    public function totals(Cart $cart): array
    {
        $hasDelivery = $cart->shippingBreakdown !== null && $cart->shippingBreakdown->items->isNotEmpty();
        $delivery = (int) $cart->shippingSubTotal?->value;
        $discount = (int) $cart->discountTotal?->value;

        return [
            'vatRows' => $this->vatRows($cart, $delivery),
            'subtotal' => $this->format($cart->subTotal),
            'discount' => $discount > 0 ? $this->format($cart->discountTotal) : null,
            'delivery' => $hasDelivery ? $this->format($cart->shippingSubTotal) : null,
            'deliveryFree' => $hasDelivery && $delivery === 0,
            'totalExVat' => $this->format(new Price((int) $cart->subTotalDiscounted->value + $delivery, $cart->currency, 1)),
            'vat' => $this->format($cart->taxTotal),
            'total' => $this->format($cart->total),
            'freeFrom' => $this->delivery->freeFromFormatted($cart),
            'deliveryCharge' => $this->format(new Price($this->delivery->charge(), $cart->currency, 1)),
        ];
    }

    /**
     * @return list<array{label: string, net: string, vat: string, zeroRated: bool}>
     */
    private function vatRows(Cart $cart, int $delivery): array
    {
        $buckets = []; // rate => [net pence, vat pence]

        foreach ($cart->lines as $line) {
            $rate = (float) ($line->taxBreakdown?->amounts->sum('percentage') ?? 0);
            $net = (int) ($line->subTotalDiscounted?->value ?? $line->subTotal->value);

            $buckets[(string) $rate] = [
                ($buckets[(string) $rate][0] ?? 0) + $net,
                ($buckets[(string) $rate][1] ?? 0) + (int) $line->taxAmount->value,
            ];
        }

        // Delivery is taxed by Lunar like a line; its rate is what its VAT came to.
        $deliveryVat = (int) $cart->shippingTaxTotal?->value;

        if ($delivery > 0) {
            $rate = (string) round($deliveryVat / $delivery * 100, 2);
            $buckets[$rate] = [($buckets[$rate][0] ?? 0) + $delivery, ($buckets[$rate][1] ?? 0) + $deliveryVat];
        }

        krsort($buckets, SORT_NUMERIC);

        $rows = [];

        foreach ($buckets as $rate => [$net, $vat]) {
            $zero = (float) $rate === 0.0;
            $shown = rtrim(rtrim(number_format((float) $rate, 2), '0'), '.');

            $rows[] = [
                'label' => $zero ? 'Zero-rated (0%)' : "VAT {$shown}%",
                'net' => $this->format(new Price($net, $cart->currency, 1)),
                'vat' => $this->format(new Price($vat, $cart->currency, 1)),
                'zeroRated' => $zero,
            ];
        }

        return $rows;
    }

    private function format(Price $price): string
    {
        return (string) $price->formatted(trimTrailingZeros: false);
    }
}
