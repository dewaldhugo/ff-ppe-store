<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Recent;

use Illuminate\Support\Collection;
use Lunar\Models\Product;

/**
 * The products a visitor has opened lately, newest first, kept in the session (no account needed). The product page records its
 * product; the Recently viewed strip on the product pages and the home page shows the rest.
 */
final class RecentlyViewed
{
    public const int MAX = 8;

    public const string KEY = 'recently_viewed';

    /** @return list<int> */
    public function ids(): array
    {
        $ids = session(self::KEY, []);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    /** Puts the product first, and drops the oldest beyond MAX. */
    public function record(int $productId): void
    {
        session([self::KEY => array_slice([$productId, ...array_diff($this->ids(), [$productId])], 0, self::MAX)]);
    }

    /**
     * The published products, newest first, without $exclude (the product being looked at).
     *
     * @return Collection<int, Product>
     */
    public function products(?int $exclude = null, int $limit = 6): Collection
    {
        $ids = array_values(array_slice(array_diff($this->ids(), $exclude === null ? [] : [$exclude]), 0, $limit + 2));

        if ($ids === []) {
            return new Collection;
        }

        $products = Product::query()
            ->whereIn('id', $ids)
            ->where('status', 'published')
            ->with(['brand', 'defaultUrl', 'thumbnail', 'variants.prices', 'variants.values.option'])
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn (int $id): ?Product => $products->get($id))->filter()->take($limit)->values();
    }
}
