<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use Illuminate\Support\Collection;
use Lunar\Models\Product;

/**
 * The products shown in the home page "Featured products" section: a random
 * pick on every page load, from published products that have an image and at
 * least one priced variant. Nothing is cached, so the pick changes on refresh.
 */
final class HomeFeaturedProducts
{
    public const int COUNT = 5;

    /**
     * @return Collection<int, Product>
     */
    public function pick(int $count = self::COUNT): Collection
    {
        return Product::query()
            ->where('status', 'published')
            ->whereHas('media', fn ($query) => $query->where('collection_name', (string) config('lunar.media.collection')))
            ->whereHas('variants.prices')
            ->with(['brand', 'defaultUrl', 'thumbnail', 'variants.prices'])
            ->inRandomOrder()
            ->limit($count)
            ->get();
    }
}
