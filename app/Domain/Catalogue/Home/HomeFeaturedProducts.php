<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Domain\Catalogue\Urls\CollectionPath;
use Illuminate\Support\Collection;
use Lunar\Models\Collection as LunarCollection;
use Lunar\Models\Product;

/**
 * The products shown in the home page "Featured products" section: a random
 * pick on every page load, from published Safety Footwear products (the
 * category and everything below it) that have an image and at least one
 * priced variant. Nothing is cached, so the pick changes on refresh.
 */
final class HomeFeaturedProducts
{
    /** Two rows of five on desktop. */
    public const int COUNT = 10;

    /** The category the pick is limited to, as a path below the shop prefix. */
    public const string CATEGORY = 'safety-footwear';

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
     * The category's own id and the ids of all its descendants.
     *
     * @return list<int>
     */
    private function categoryIds(): array
    {
        $id = array_search(CollectionPath::fromSegments(self::CATEGORY), (new CollectionPath)->all(), true);

        if ($id === false) {
            return [];
        }

        $category = LunarCollection::query()->find($id);

        if ($category === null) {
            return [];
        }

        return $category->descendants()->pluck('id')->push($category->id)->map(fn ($key): int => (int) $key)->all();
    }
}
