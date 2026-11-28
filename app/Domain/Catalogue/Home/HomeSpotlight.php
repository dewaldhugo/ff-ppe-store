<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Models\SpotlightBanner;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Product;

/**
 * The campaign for the home page's Product Spotlight: one, picked at random, together with the published product it is about.
 *
 * The campaigns are the ones added in the admin (Spotlight banners: a banner picture and a product). While none have been added
 * at all, the campaigns of config/homepage.php (`spotlights`) are used. Either way a campaign whose product is not in the
 * catalogue, or is not published, is left out, so the page never links to a product that is not there; with none left, the
 * section is not shown. Reads Lunar models only, as the storefront must.
 *
 * A campaign is an array with `slug`, `alt`, `width`, `height` and the picture: `image_url` (the admin's upload) or `file` and
 * `webp` (config: a file of public/images/home).
 */
final class HomeSpotlight
{
    /**
     * @return array{spotlight: array<string, mixed>, product: Product}|null
     */
    public function pick(): ?array
    {
        $available = $this->available();

        return $available === [] ? null : $available[array_rand($available)];
    }

    /**
     * Every campaign that has a published product, in order.
     *
     * @return list<array{spotlight: array<string, mixed>, product: Product}>
     */
    public function available(): array
    {
        if (Schema::hasTable('spotlight_banners') && SpotlightBanner::query()->exists()) {
            return $this->fromAdmin();
        }

        return $this->fromConfig();
    }

    /**
     * Copies the campaigns of config/homepage.php into the admin's list as banners (the picture is copied to the public disk), for a
     * store that wants to edit them there. A campaign whose product is not published, or whose picture file is missing, is skipped,
     * and a product that already has a banner is left alone, so it can be run again. Returns how many were added.
     */
    public function importStandard(): int
    {
        $position = (int) SpotlightBanner::query()->max('position');
        $added = 0;

        foreach ($this->fromConfig() as $campaign) {
            $spotlight = $campaign['spotlight'];
            $product = $campaign['product'];
            $source = public_path('images/home/'.$spotlight['file'].'.jpg');

            if (! is_file($source) || SpotlightBanner::query()->where('product_id', $product->id)->exists()) {
                continue;
            }

            $path = SpotlightBanner::DIRECTORY.'/'.$spotlight['file'].'.jpg';

            Storage::disk(SpotlightBanner::DISK)->put($path, (string) file_get_contents($source), 'public');

            SpotlightBanner::query()->create([
                'product_id' => $product->id,
                'image' => $path,
                'alt' => $spotlight['alt'] ?? null,
                'active' => true,
                'position' => ++$position,
            ]);

            $added++;
        }

        return $added;
    }

    /** @return list<array{spotlight: array<string, mixed>, product: Product}> */
    private function fromAdmin(): array
    {
        $available = [];

        $banners = SpotlightBanner::query()->where('active', true)->orderBy('position')->orderBy('id')
            ->with(['product' => fn ($query) => $query->where('status', 'published')->with(['brand', 'urls', 'defaultUrl', 'variants.prices'])])
            ->get();

        foreach ($banners as $banner) {
            $product = $banner->product;

            if ($product === null || ! Storage::disk(SpotlightBanner::DISK)->exists($banner->image)) {
                continue;
            }

            [$width, $height] = @getimagesize(Storage::disk(SpotlightBanner::DISK)->path($banner->image)) ?: [1200, 1200];

            $available[] = [
                'spotlight' => [
                    'slug' => (string) ($product->defaultUrl?->slug ?? $product->id),
                    'image_url' => $banner->imageUrl(),
                    'file' => null,
                    'webp' => false,
                    'alt' => filled($banner->alt) ? (string) $banner->alt : (string) $product->translateAttribute('name'),
                    'width' => $width,
                    'height' => $height,
                ],
                'product' => $product,
            ];
        }

        return $available;
    }

    /** @return list<array{spotlight: array<string, mixed>, product: Product}> */
    private function fromConfig(): array
    {
        $spotlights = array_values(array_filter(
            (array) config('homepage.spotlights', []),
            fn (mixed $spotlight): bool => is_array($spotlight) && filled($spotlight['slug'] ?? null) && filled($spotlight['file'] ?? null),
        ));

        if ($spotlights === []) {
            return [];
        }

        $slugs = array_map(fn (array $spotlight): string => (string) $spotlight['slug'], $spotlights);

        $products = Product::query()
            ->where('status', 'published')
            ->whereHas('urls', fn ($urls) => $urls->whereIn('slug', $slugs))
            ->with(['brand', 'urls', 'defaultUrl', 'variants.prices'])
            ->get();

        $available = [];

        foreach ($spotlights as $spotlight) {
            $product = $products->first(fn (Product $candidate): bool => $candidate->urls->contains('slug', $spotlight['slug']));

            if ($product !== null) {
                $available[] = ['spotlight' => $spotlight, 'product' => $product];
            }
        }

        return $available;
    }
}
