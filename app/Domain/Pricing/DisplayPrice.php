<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Lunar\DataTypes\Price;

/**
 * A price ready to show to the customer.
 *
 * The store sells to business buyers, so every price is shown ex VAT and
 * labelled as such. The compare price is the manufacturer's recommended
 * retail price (RRP), also ex VAT, and is always labelled as that: never a
 * bare struck-through amount.
 */
final readonly class DisplayPrice
{
    public const string SUFFIX = 'ex VAT';

    public function __construct(
        public Price $price,
        public ?Price $comparePrice,
        /** True when VAT is 0% for individuals but 20% when buying for employees. */
        public bool $vatAppliesForEmployers,
    ) {}

    public function formatted(): string
    {
        return (string) $this->price->formatted(trimTrailingZeros: false);
    }

    /**
     * The manufacturer's RRP, ex VAT, or null when there is none.
     */
    public function rrpFormatted(): ?string
    {
        return $this->comparePrice === null
            ? null
            : (string) $this->comparePrice->formatted(trimTrailingZeros: false);
    }

    public function suffix(): string
    {
        return self::SUFFIX;
    }
}
