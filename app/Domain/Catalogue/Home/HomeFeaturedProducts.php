<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\UnknownCatalogueKey;
use Illuminate\Support\Collection;
use Lunar\Models\Product;

/**
 * The products shown in the home page "Featured products" section: a random
 * pick on every page load, from published products in the Safety Footwear and
 * PPE & Gear categories (each category and everything below it) that have an
 * image and at least one priced variant. Nothing is cached, so the pick
 * changes on refresh.
 */
final class HomeFeaturedProducts
{
    /** Two rows of five on desktop. */
    public const int COUNT = 10;

    /**
     * The categories the pick is limited to, by contract handle.
     *
     * @var list<string>
     */
    public const array CATEGORIES = ['safety-footwear', 'ppe-gear'];

    /**
     * @return Collection<int, Product>
     */
    public function pick(int $count = self::COUNT): Collection
    {
        $categoryIds = $this->categoryIds();

        if ($categoryIds === []) {
            return new Collection;
        }

        return Product::query()
            ->where('status', 'published')
            ->whereHas('collections', fn ($query) => $query->whereKey($categoryIds))
            ->whereHas('media', fn ($query) => $query->where('collection_name', (string) config('lunar.media.collection')))
            ->whereHas('variants.prices')
            ->with(['brand', 'defaultUrl', 'thumbnail', 'variants.prices'])
            ->inRandomOrder()
            ->limit($count)
            ->get();
    }

    /**
     * The ids of the allowed categories and all their descendants. A category that does
     * not exist yet (an unseeded install) is skipped, so the home page never fails on it.
     *
     * @return list<int>
     */
    private function categoryIds(): array
    {
        $ids = [];

        foreach (self::CATEGORIES as $handle) {
            try {
                $category = CatalogueKeys::collection($handle);
            } catch (UnknownCatalogueKey) {
                continue;
            }

            $ids = [...$ids, ...$category->descendants()->pluck('id')->push($category->id)->map(fn ($key): int => (int) $key)->all()];
        }

        return array_values(array_unique($ids));
    }
}
