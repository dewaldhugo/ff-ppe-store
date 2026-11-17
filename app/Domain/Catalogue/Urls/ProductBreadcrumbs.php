<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls;

use Illuminate\Database\Eloquent\Builder;
use Lunar\Models\Collection;
use Lunar\Models\Product;

/**
 * The breadcrumb trail on a product page: Products, the product's category
 * path, then the product itself (no link). There is no Home crumb: the logo
 * and the menu already link home. The category is the
 * product's deepest one in the `categories` group; ties go to the lowest id,
 * so the trail is the same on every visit. Category links go through
 * CategoryUrl, like every other category link.
 */
final class ProductBreadcrumbs
{
    public function __construct(private readonly CategoryUrl $urls) {}

    /**
     * @return list<array{name: string, url: string|null}> the last item has no URL: it is the current page
     */
    public function for(Product $product): array
    {
        $crumbs = [
            ['name' => 'Products', 'url' => route('shop')],
        ];

        $category = $this->deepestCategory($product);

        foreach ($category === null ? [] : [...$category->ancestors, $category] as $collection) {
            $url = $this->urls->for($collection);

            if ($url !== null) {
                $crumbs[] = ['name' => (string) $collection->translateAttribute('name'), 'url' => $url];
            }
        }

        $crumbs[] = ['name' => (string) $product->translateAttribute('name'), 'url' => null];

        return $crumbs;
    }

    private function deepestCategory(Product $product): ?Collection
    {
        return $product->collections()
            ->whereHas('group', fn (Builder $query) => $query->where('handle', CollectionPath::GROUP_HANDLE))
            ->get()
            ->sortBy([
                fn (Collection $a, Collection $b): int => $b->ancestors->count() <=> $a->ancestors->count(),
                fn (Collection $a, Collection $b): int => $a->id <=> $b->id,
            ])
            ->first();
    }
}
