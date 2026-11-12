<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Search;

/**
 * The product index settings defined under "Search index" in CLAUDE.md.
 *
 * Applied by `php artisan catalogue:sync-search-settings`. Bump VERSION with
 * every change so deploy logs show which settings are live.
 */
final class IndexSettings
{
    public const int VERSION = 1;

    /**
     * Order matters: Meilisearch ranks matches in earlier attributes higher.
     * `skus` is not in the contract list; it is kept last so the admin panel,
     * which searches this same index, can still find products by SKU.
     *
     * @return list<string>
     */
    public static function searchable(): array
    {
        return ['name', 'title', 'brand', 'key_points', 'safety_class', 'standards', 'skus'];
    }

    /**
     * @return list<string>
     */
    public static function filterable(): array
    {
        return [
            'collection_ids',
            'brand',
            'safety_class',
            'features',
            'gender',
            'standards',
            'cut',
            'toecap',
            'colours_in_stock',
            'sizes_in_stock',
            'variants_in_stock',
            'in_stock',
            'price_min_ex_vat',
            'price_min_inc_vat',
            'status',
        ];
    }

    /**
     * @return list<string>
     */
    public static function sortable(): array
    {
        return ['price_min_inc_vat', 'price_min_ex_vat', 'created_at', 'sort_order'];
    }

    /**
     * Joins a colour and a size into the value stored in `variants_in_stock`.
     */
    public static function variantPair(string $colourSlug, string $size): string
    {
        return $colourSlug.'|'.$size;
    }
}
