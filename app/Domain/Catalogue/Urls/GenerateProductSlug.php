<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls;

use Illuminate\Support\Str;
use Lunar\Models\Product;
use Lunar\Models\Url;

/**
 * The single implementation of the product slug rule in the data contract:
 * Str::slug(brand . ' ' . name), with a numeric suffix (-2, -3, ...) on
 * collision, in order of creation.
 *
 * Call it once, when a product is created. A product's slug never changes
 * afterwards, even when the vendor renames the product.
 */
final class GenerateProductSlug
{
    public function handle(string $brand, string $name): string
    {
        $base = Str::slug(trim($brand.' '.$name));

        if ($base === '') {
            $base = 'product';
        }

        $taken = Url::query()
            ->where('element_type', Product::morphName())
            ->where(function ($query) use ($base): void {
                $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%');
            })
            ->pluck('slug')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $suffix = 2;

        while (in_array($base.'-'.$suffix, $taken, true)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }
}
