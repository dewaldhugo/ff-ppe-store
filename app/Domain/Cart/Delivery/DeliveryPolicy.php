<?php

declare(strict_types=1);

namespace App\Domain\Cart\Delivery;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use Lunar\DataTypes\Price;
use Lunar\DataTypes\ShippingOption;
use Lunar\Models\Cart;
use Lunar\Models\Currency;

/**
 * The delivery rule, in one place: a flat charge, free when the goods come to MORE than the
 * free-over amount (exactly that amount still pays). Both amounts are pence, ex VAT, in
 * `config/store.php` (`delivery`). More rules (by weight, by address, express) are expected:
 * they go here, and the checkout and the cart page read the option from here, never the numbers.
 *
 * The goods value is the sum of the lines' sub totals ex VAT, before discounts and before
 * delivery. Delivery is standard rated. The wording shown to customers (notice()) is built from
 * the same two amounts, so it can never disagree with what is charged.
 */
final class DeliveryPolicy
{
    public const string IDENTIFIER = 'standard-delivery';

    public function option(Cart $cart): ShippingOption
    {
        $goods = (int) $cart->lines->sum(fn ($line): int => (int) $line->subTotal?->value);

        $free = $goods > $this->freeOver();

        return new ShippingOption(
            name: 'Standard delivery',
            description: $free ? 'Free: your goods come to over '.$this->freeOverFormatted($cart->currency).' ex VAT.' : null,
            identifier: self::IDENTIFIER,
            price: new Price($free ? 0 : $this->charge(), $cart->currency, 1),
            taxClass: CatalogueKeys::taxClass('standard'),
        );
    }

    /** Pence, ex VAT. Delivery is free when the goods come to more than this. */
    public function freeOver(): int
    {
        return (int) config('store.delivery.free_over');
    }

    /** Pence, ex VAT. */
    public function charge(): int
    {
        return (int) config('store.delivery.charge');
    }

    public function freeOverFormatted(?Currency $currency = null): string
    {
        return $this->format($this->freeOver(), $currency);
    }

    public function chargeFormatted(?Currency $currency = null): string
    {
        return $this->format($this->charge(), $currency);
    }

    /** The note customers see on the cart, the checkout and the quick order page. */
    public function notice(?Currency $currency = null): string
    {
        return sprintf(
            'Orders over %s ex VAT get free delivery. Otherwise delivery is %s ex VAT.',
            $this->freeOverFormatted($currency),
            $this->chargeFormatted($currency),
        );
    }

    private function format(int $pence, ?Currency $currency): string
    {
        return (string) (new Price($pence, $currency ?? Currency::getDefault(), 1))->formatted(trimTrailingZeros: false);
    }
}
