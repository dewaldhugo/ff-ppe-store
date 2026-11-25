<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Options;

use Lunar\Models\Product;

/**
 * The colour swatches on a product card: one per colour, in variant order. Needs `variants.values.option` loaded.
 */
final class ProductSwatches
{
    /**
     * @return list<array{name: string, hexes: list<string>}>
     */
    public static function for(Product $product): array
    {
        $swatches = [];

        foreach ($product->variants as $variant) {
            $value = $variant->values->first(fn ($value): bool => $value->option?->handle === 'colour');

            if ($value === null) {
                continue;
            }

            $name = (string) $value->translate('name');
            $hex = $value->meta['hex'] ?? null;

            $swatches[$name] ??= [
                'name' => $name,
                'hexes' => ColourSwatch::hexes($name, is_string($hex) ? $hex : null),
            ];
        }

        return array_values($swatches);
    }
}
