<?php

declare(strict_types=1);

namespace App\Domain\Cart\Delivery;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use Lunar\DataTypes\Price;
use Lunar\DataTypes\ShippingOption;
use Lunar\Models\Cart;

/**
 * The delivery rule, in one place: a flat charge, free when the goods come to the free-from
 * amount or more. Both amounts are pence, ex VAT, in `config/store.php` (`delivery`). More
 * rules (by weight, by address, express) are expected: they go here, and the checkout and
 * the cart page read the option from here, never the numbers.
 *
 * The goods value is the sum of the lines' sub totals ex VAT, before discounts and before
 * delivery. Delivery is standard rated.
 */
final class DeliveryPolicy
{
    public const string IDENTIFIER = 'standard-delivery';

    public function option(Cart $cart): ShippingOption
    {
        $goods = (int) $cart->lines->sum(fn ($line): int => (int) $line->subTotal?->value);

        $free = $goods >= $this->freeFrom();

        return new ShippingOption(
            name: 'Standard delivery',
            description: $free ? 'Free: your goods come to '.$this->freeFromFormatted($cart).' or more ex VAT.' : null,
            identifier: self::IDENTIFIER,
            price: new Price($free ? 0 : $this->charge(), $cart->currency, 1),
            taxClass: CatalogueKeys::taxClass('standard'),
        );
    }

    /** Pence, ex VAT. */
    public function freeFrom(): int
    {
        return (int) config('store.delivery.free_from');
    }

    /** Pence, ex VAT. */
    public function charge(): int
    {
        return (int) config('store.delivery.charge');
    }

    public function freeFromFormatted(Cart $cart): string
    {
        return (string) (new Price($this->freeFrom(), $cart->currency, 1))->formatted(trimTrailingZeros: false);
    }
}
