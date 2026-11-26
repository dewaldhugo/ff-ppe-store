<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

use App\Domain\VendorSync\Data\NormalisedProduct;

/**
 * What the split detector compares: a mapped Footsure product reduced to its
 * identity (brand and name), the facts that must agree for two ProductIDs to
 * be one product line, and the colour and size pairs it sells.
 *
 * Built from the mapper's own output, never from raw feed columns, so a row
 * the mapper skips (a ghost row, a duplicate, an unusable size) counts for
 * nothing here either, and the same code reads a feed record or a stored one.
 */
final readonly class ProductSignature
{
    /**
     * @param  array<string, list<string>>  $sizesByColour  Size option value names, per colour name.
     * @param  array<string, string>  $facts  The facts that must be equal, by name.
     */
    public function __construct(
        public string $vendorProductId,
        public string $label,
        public string $line,
        public array $facts,
        public array $sizesByColour,
    ) {}

    public static function from(NormalisedProduct $product): ?self
    {
        // Nothing to place on another product, and nothing another could take.
        if ($product->variants === []) {
            return null;
        }

        $sizes = [];

        foreach ($product->variants as $variant) {
            $sizes[$variant->colour][] = $variant->shoeSize;
        }

        return new self(
            $product->vendorProductId,
            $product->label,
            self::normalise((string) $product->brand).'|'.self::normalise($product->name),
            [
                'size scale' => $product->variants[0]->sizeOptionHandle,
                'product type' => $product->productType,
                'category' => implode(',', $product->collections),
                'safety class' => (string) $product->safetyClass,
                'standards' => implode(',', $product->standards),
                'gender' => (string) $product->gender,
            ],
            array_map(fn (array $names): array => array_values(array_unique($names)), $sizes),
        );
    }

    /**
     * @return list<string>
     */
    public function colours(): array
    {
        return array_keys($this->sizesByColour);
    }

    /**
     * The colour and size pairs, as `colour|size`.
     *
     * @return list<string>
     */
    public function pairs(): array
    {
        $pairs = [];

        foreach ($this->sizesByColour as $colour => $sizes) {
            foreach ($sizes as $size) {
                $pairs[] = $colour.'|'.$size;
            }
        }

        return $pairs;
    }

    private static function normalise(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
