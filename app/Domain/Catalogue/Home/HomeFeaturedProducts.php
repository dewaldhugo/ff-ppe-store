<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\UnknownCatalogueKey;
use Illuminate\Database\Eloquent\Collection;
use Lunar\Models\Product;

/**
 * The products shown in the home page "Featured products" section: half of them (4 of 8) picked
 * at random from published products in Safety Footwear and half from PPE & Gear (each category and
 * everything below it), that have an image and at least one priced variant, and then shown in random
 * order. A product in both categories is shown once. When one category has too few products, the
 * gap is filled from the other, so the section stays full. Nothing is cached, so the pick and the
 * order change on refresh.
 */
final class HomeFeaturedProducts
{
    /** Four from each category: two even rows of four on desktop and four of two on mobile, never a lone product. */
    public const int COUNT = 8;

    /**
     * The categories the pick is limited to, by contract handle. Each supplies an equal share.
     *
     * @var list<string>
     */
    public const array CATEGORIES = ['safety-footwear', 'ppe-gear'];

    /**
     * @return Collection<int, Product>
     */
    public function pick(int $count = self::COUNT): Collection
    {
        $groups = array_map(fn (string $handle): array => $this->categoryIds([$handle]), self::CATEGORIES);
        $share = intdiv($count, count(self::CATEGORIES));

        $picked = new Collection;

        foreach ($groups as $ids) {
            $picked = $picked->concat($this->random($ids, $share, $picked->modelKeys()));
        }

        // A category with fewer products than its share leaves a gap: fill it from either category.
        if ($picked->count() < $count) {
            $picked = $picked->concat($this->random(array_merge(...$groups), $count - $picked->count(), $picked->modelKeys()));
        }

        // Footwear and gear are picked separately, so mix them: the page shows them in random order.
        return $picked->shuffle()->values();
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  list<int|string>  $exclude  Products already picked.
     * @return Collection<int, Product>
     */
    private function random(array $categoryIds, int $limit, array $exclude): Collection
    {
        if ($categoryIds === [] || $limit <= 0) {
            return new Collection;
        }

        return Product::query()
            ->where('status', 'published')
            ->whereNotIn('id', $exclude)
            ->whereHas('collections', fn ($query) => $query->whereKey($categoryIds))
            ->whereHas('media', fn ($query) => $query->where('collection_name', (string) config('lunar.media.collection')))
            ->whereHas('variants.prices')
            ->with(['brand', 'defaultUrl', 'thumbnail', 'variants.prices'])
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }

    /**
     * The ids of the given categories and all their descendants. A category that does not exist yet
     * (an unseeded install) is skipped, so the home page never fails on it.
     *
     * @param  list<string>  $handles
     * @return list<int>
     */
    private function categoryIds(array $handles): array
    {
        $ids = [];

        foreach ($handles as $handle) {
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
