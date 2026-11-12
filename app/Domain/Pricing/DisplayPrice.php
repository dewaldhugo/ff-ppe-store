<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Lunar\DataTypes\Price;

/**
 * A price ready to show to the customer in their VAT display mode.
 */
final readonly class DisplayPrice
{
    public function __construct(
        public Price $price,
        public ?Price $comparePrice,
        public VatDisplayMode $mode,
        /** True when VAT is 0% for individuals but 20% when buying for employees. */
        public bool $vatAppliesForEmployers,
    ) {}

    public function formatted(): string
    {
        return (string) $this->price->formatted(trimTrailingZeros: false);
    }

    public function compareFormatted(): ?string
    {
        return $this->comparePrice === null
            ? null
            : (string) $this->comparePrice->formatted(trimTrailingZeros: false);
    }

    public function suffix(): string
    {
        return $this->mode->label();
    }
}
