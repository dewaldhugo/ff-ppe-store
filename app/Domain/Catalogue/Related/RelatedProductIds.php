<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Related;

use App\Domain\Catalogue\Search\CatalogueSearch;
use App\Domain\Catalogue\Search\ListingQuery;
use App\Domain\Catalogue\Urls\ProductBreadcrumbs;
use Lunar\Models\Product;
use Throwable;

/**
 * Which products the product page offers as "Related products".
 *
 * The vendors' own links come first: Lunar's native `cross-sell` associations (data contract, Products). Most
 * products have none, so when there are fewer than MINIMUM the list is topped up with in-stock products from the
 * same category, found through the search index like every other listing. The peers start after this product's own
 * id and wrap round, so the products in one category do not all point at the same first few.
 *
 * When the index cannot be reached the vendors' links are shown alone (and the error reported) rather than taking
 * the product page down.
 */
final class RelatedProductIds
{
    /** Fewer than this many vendor links and the list is topped up from the category: six, two rows of three. */
    public const int MINIMUM = 6;

    /** The most products ever shown. */
    public const int MAXIMUM = 6;

    public function __construct(
        private readonly CatalogueSearch $search,
        private readonly ProductBreadcrumbs $categories,
    ) {}

    /**
     * Product ids in display order. Published status is checked again when they are loaded.
     *
     * @return list<int>
     */
    public function for(Product $product): array
    {
        $ids = $product->associations()->crossSell()->pluck('product_target_id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $product->id)
            ->unique()
            ->take(self::MAXIMUM)
            ->values()
            ->all();

        if (count($ids) >= self::MINIMUM) {
            return $ids;
        }

        return [...$ids, ...array_slice($this->categoryPeers($product, $ids), 0, self::MINIMUM - count($ids))];
    }

    /**
     * @param  list<int>  $exclude  Already chosen.
     * @return list<int>
     */
    private function categoryPeers(Product $product, array $exclude): array
    {
        $category = $this->categories->deepestCategory($product);

        if ($category === null) {
            return [];
        }

        try {
            $result = $this->search->search(new ListingQuery(
                collectionId: $category->id,
                inStockOnly: true,
                perPage: 48,
            ));
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $peers = array_values(array_filter(
            $result->productIds,
            fn (int $id): bool => $id !== $product->id && ! in_array($id, $exclude, true),
        ));

        sort($peers);

        $after = array_values(array_filter($peers, fn (int $id): bool => $id > $product->id));
        $before = array_values(array_filter($peers, fn (int $id): bool => $id < $product->id));

        return [...$after, ...$before];
    }
}
