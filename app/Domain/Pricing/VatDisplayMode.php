<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * How prices are shown to the customer. Prices are stored ex VAT; this only
 * controls display, sorting and price filtering.
 */
enum VatDisplayMode: string
{
    case IncVat = 'inc';
    case ExVat = 'ex';

    public static function default(): self
    {
        return self::IncVat;
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
