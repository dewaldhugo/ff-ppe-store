<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * How a price is shown. Prices are stored ex VAT.
 *
 * The storefront shows every price ex VAT, with an `ex VAT` suffix, and has
 * no toggle: it sells to trade buyers. The inc VAT mode remains for the
 * places that must state a VAT-inclusive figure whatever the storefront
 * shows (structured data for search engines, and later the checkout).
 */
enum VatDisplayMode: string
{
    case IncVat = 'inc';
    case ExVat = 'ex';

    /**
     * The mode the storefront displays, sorts and filters prices in.
     */
    public static function default(): self
    {
        return self::ExVat;
    }

    /**
     * The search index field holding a product's lowest price in this mode.
     */
    public function priceField(): string
    {
        return match ($this) {
            self::IncVat => 'price_min_inc_vat',
            self::ExVat => 'price_min_ex_vat',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::IncVat => 'inc VAT',
            self::ExVat => 'ex VAT',
        };
    }
}
