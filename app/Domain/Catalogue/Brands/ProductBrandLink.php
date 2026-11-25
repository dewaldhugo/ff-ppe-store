<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Brands;

use App\Console\Commands\TrimBrandLogos;
use App\Models\StorefrontBrand;
use Illuminate\Support\Str;
use Lunar\Models\Product;

/**
 * The brand shown on a product page: its name, its logo (when the storefront has one) and the /brands/{slug} page it
 * links to. Same slug rule as BrandDirectory, without its search index counts: a Lunar brand that is a member of a
 * storefront brand is shown as that brand, any other under its own name with no logo.
 */
final class ProductBrandLink
{
    /**
     * @return ?array{name: string, slug: string, logoUrl: ?string}
     */
    public function for(Product $product): ?array
    {
        $brand = $product->brand;

        if ($brand === null) {
            return null;
        }

        $storefront = StorefrontBrand::query()
            ->whereHas('members', fn ($query) => $query->whereKey($brand->id))
            ->first();

        if ($storefront !== null) {
            return [
                'name' => $storefront->name,
                'slug' => $storefront->slug,
                'logoUrl' => $storefront->logo_path === null ? null : asset($this->trimmed($storefront->logo_path)),
            ];
        }

        $name = (string) $brand->name;
        $slug = Str::slug($name);

        return $slug === '' ? null : ['name' => $name, 'slug' => $slug, 'logoUrl' => null];
    }

    /**
     * The trimmed copy of a logo (php artisan catalogue:trim-brand-logos), or the logo itself when there is none yet.
     */
    private function trimmed(string $logoPath): string
    {
        $trimmed = TrimBrandLogos::DIRECTORY.'/'.basename($logoPath);

        return is_file(public_path($trimmed)) ? $trimmed : $logoPath;
    }
}
