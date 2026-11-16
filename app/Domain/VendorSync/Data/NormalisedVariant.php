<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Data;

/**
 * One sellable variant in contract terms. Unknown values are null, never
 * guessed. Money is integer pence, ex VAT.
 */
final readonly class NormalisedVariant
{
    public function __construct(
        // The vendor's SKU identifier (vendor_offers.vendor_sku).
        public string $vendorSku,
        // Lunar SKU: {VENDOR_CODE}-{vendor_sku}.
        public string $sku,
        public ?string $ean,
        // Customer-facing colour name, title case.
        public string $colour,
        public ?string $colourHex,
        // UK size as the option value, e.g. `10`, `10.5`, `Kids 10`.
        public string $shoeSize,
        public float $sizeSort,
        public ?string $euSize,
        // The vendor's stock figure, before the safety buffer.
        public int $vendorStock,
        // False when a rule forces the variant to stock 0.
        public bool $sellable,
        // Null only when the vendor priced the SKU in a currency other than
        // GBP: the variant is then unpriced (no price row, no cost), never
        // sellable, and its product is drafted `non_gbp_price`.
        public ?int $price,
        public ?int $comparePrice,
        public ?int $costPrice,
        // Contract tax class handle.
        public string $taxClass,
        public ?float $weightKg,
        public ?float $lengthMm,
        public ?float $widthMm,
        public ?float $heightMm,
        // The vendor's own wording, used to place orders manually.
        public string $vendorProductName,
        public ?string $vendorColour,
        public ?string $vendorSize,
    ) {}
}
