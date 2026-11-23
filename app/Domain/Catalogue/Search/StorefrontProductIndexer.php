<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Search;

use App\Domain\Catalogue\Markets\ShipsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Lunar\Models\Product;
use Lunar\Search\ProductIndexer;

/**
 * Extends Lunar's product indexing (mapped in config/lunar/search.php) with
 * the storefront fields described in docs/search.md.
 *
 * Lunar's own fields are kept because the admin panel searches this index.
 * One document per product, drafts included: the storefront always filters
 * on status = published.
 */
final class StorefrontProductIndexer extends ProductIndexer
{
    /**
     * @return list<string>
     */
    public function getSortableFields(): array
    {
        return array_values(array_unique([...parent::getSortableFields(), ...IndexSettings::sortable()]));
    }

    /**
     * @return list<string>
     */
    public function getFilterableFields(): array
    {
        return array_values(array_unique([...parent::getFilterableFields(), ...IndexSettings::filterable()]));
    }

    public function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with([
            'thumbnail',
            'productType',
            'brand',
            'urls',
            'collections',
            'variants.values.option',
            'variants.prices',
            'variants.taxClass',
        ]);
    }

    /**
     * @param  Product  $model
     * @return array<string, mixed>
     */
    public function toSearchableArray(Model $model): array
    {
        $model->loadMissing([
            'productType', 'brand', 'collections', 'variants.values.option', 'variants.prices',
        ]);

        $inStock = $model->variants->filter(fn ($variant): bool => $variant->stock > 0);

        $colours = [];
        $sizes = [];
        $pairs = [];

        foreach ($inStock as $variant) {
            $colour = $variant->values->firstWhere('option.handle', 'colour');
            // Every product is sized by exactly one of these two options.
            $size = $variant->values->firstWhere('option.handle', 'shoe_size')
                ?? $variant->values->firstWhere('option.handle', 'garment_size');

            $colourSlug = $colour === null ? null : Str::slug($colour->translate('name'));
            $sizeName = $size?->translate('name');

            if ($colourSlug !== null) {
                $colours[$colourSlug] = true;
            }

            if ($sizeName !== null) {
                $sizes[(string) $sizeName] = true;
            }

            if ($colourSlug !== null && $sizeName !== null) {
                $pairs[IndexSettings::variantPair($colourSlug, (string) $sizeName)] = true;
            }
        }

        return [
            ...parent::toSearchableArray($model),
            'name' => $model->translateAttribute('name'),
            'title' => $model->translateAttribute('title'),
            'slug' => $model->urls->firstWhere('default', true)?->slug,
            'key_points' => $model->attr('key_points') ?? [],
            'safety_class' => $model->attr('safety_class'),
            'standards' => $model->attr('standards') ?? [],
            'features' => $model->attr('features') ?? [],
            'gender' => $model->attr('gender'),
            'cut' => $model->attr('cut'),
            'toecap' => $model->attr('toecap'),
            'collection_ids' => $this->collectionIds($model),
            'colours_in_stock' => array_map(strval(...), array_keys($colours)),
            'sizes_in_stock' => array_map(strval(...), array_keys($sizes)),
            'variants_in_stock' => array_map(strval(...), array_keys($pairs)),
            'in_stock' => $inStock->isNotEmpty(),
            // The markets the product can be delivered to, from its vendor (App\Domain\Catalogue\Markets\ShipsTo).
            'ships_to' => app(ShipsTo::class)->forProduct($model),
            'price_min_ex_vat' => $this->minimumPriceExVat($model),
            // No source in the contract yet. Reserved for merchandising.
            'sort_order' => 0,
        ];
    }

    /**
     * The product's collections and all of their ancestors.
     *
     * @return list<int>
     */
    private function collectionIds(Product $product): array
    {
        $ids = [];

        foreach ($product->collections as $collection) {
            $ids[] = $collection->id;

            foreach ($collection->ancestors as $ancestor) {
                $ids[] = $ancestor->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Lowest variant price in pence, ex VAT. Null when no variant has a price
     * (a draft product with a non-GBP vendor price).
     */
    private function minimumPriceExVat(Product $product): ?int
    {
        $exVat = null;

        foreach ($product->variants as $variant) {
            foreach ($variant->prices as $price) {
                if ($price->min_quantity > 1) {
                    continue;
                }

                $price->setRelation('priceable', $variant);

                $ex = $price->priceExTax()->value;

                $exVat = $exVat === null ? $ex : min($exVat, $ex);
            }
        }

        return $exVat;
    }
}
