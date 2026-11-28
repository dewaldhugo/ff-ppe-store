<?php

declare(strict_types=1);

namespace App\Domain\Cart;

use App\Domain\Catalogue\Related\RelatedProductIds;
use Illuminate\Support\Collection;
use Lunar\Models\Cart;
use Lunar\Models\Product;

/**
 * "You may also need" on the cart page: what goes with the products in the cart. It is the product page's related products (the
 * vendors' cross-sell links first, then peers from the same category, RelatedProductIds) of the first few products in the cart,
 * less what is already in the cart, a few from each so one product does not take every place.
 */
final class CartCrossSell
{
    public const int LIMIT = 4;

    /** How many cart products are asked, so the page does not search for a long cart's every line. */
    private const int FROM = 3;

    public function __construct(private readonly RelatedProductIds $related) {}

    /** @return Collection<int, Product> */
    public function for(Cart $cart): Collection
    {
        $inCart = $cart->lines->map(fn ($line): ?int => $line->purchasable?->product_id)->filter()->unique()->values();

        if ($inCart->isEmpty()) {
            return new Collection;
        }

        $lists = $inCart->take(self::FROM)
            ->map(fn (int $id): array => array_values(array_diff($this->related->for(Product::query()->findOrFail($id)), $inCart->all())))
            ->all();

        // One from each product in turn, then the next of each, so the choice is spread across the cart.
        $ids = [];

        for ($i = 0; count($ids) < self::LIMIT * 2 && $i < RelatedProductIds::MAXIMUM; $i++) {
            foreach ($lists as $list) {
                if (isset($list[$i]) && ! in_array($list[$i], $ids, true)) {
                    $ids[] = $list[$i];
                }
            }
        }

        if ($ids === []) {
            return new Collection;
        }

        $products = Product::query()
            ->whereIn('id', $ids)
            ->where('status', 'published')
            ->with(['brand', 'defaultUrl', 'thumbnail', 'variants.prices', 'variants.values.option'])
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn (int $id): ?Product => $products->get($id))->filter()->take(self::LIMIT)->values();
    }
}
