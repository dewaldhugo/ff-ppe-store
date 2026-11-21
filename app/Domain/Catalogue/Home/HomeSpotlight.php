<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use Lunar\Models\Product;

/**
 * The campaign for the home page's Product Spotlight (`config/homepage.php`, `spotlights`): one, picked at random,
 * together with the published product it is about. A campaign whose product is not in the catalogue, or is not
 * published, is left out, so the page never links to a product that is not there. With none left, the section is
 * not shown. Reads Lunar models only (a product by its URL slug), as the storefront must.
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
     * Every campaign that has a published product, in the order of the config.
     *
     * @return list<array{spotlight: array<string, mixed>, product: Product}>
     */
    public function available(): array
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
