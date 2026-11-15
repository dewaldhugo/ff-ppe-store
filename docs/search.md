# Search index

One Meilisearch document per product, written by Lunar's product indexing through the storefront's custom indexer (`config/lunar/search.php`, `indexers`). Drafts are indexed too because the admin panel searches the same index; the storefront always filters on `status = published`.

- Searchable: `name`, `title`, `brand`, `key_points`, `safety_class`, `standards`, `skus` (so the admin panel finds products by SKU).
- Filterable: `collection_ids` (the product's collections and all their ancestors), `brand`, `safety_class`, `features`, `gender`, `standards`, `cut`, `toecap`, `colours_in_stock`, `sizes_in_stock`, `variants_in_stock` (colour and size pairs, e.g. `navy|9`), `in_stock` (bool), `price_min_ex_vat`, `price_min_inc_vat`, `status`. Prices are in pence.
- Sortable: `price_min_inc_vat`, `price_min_ex_vat`, `created_at`, `sort_order`.
- `price_min_inc_vat` (individual-use rate) is indexed but not used by the storefront, which filters and sorts on `price_min_ex_vat` only. Nothing in the storefront, commands, tests or Lunar's admin code reads it.
- Index settings are applied by the versioned command `php artisan catalogue:sync-search-settings`, run on every deploy. Never change them by hand.
- Listings and facets query Meilisearch only, through `App\Domain\Catalogue\Search\CatalogueSearch`. No direct Eloquent filtering for listings.
- `SCOUT_QUEUE=true` in production, `false` locally and in tests.
- Bulk writers (the ingestion module, seeders, imports) must wrap their writes in `withoutSyncingToSearch()` and then reindex the affected products in one batch afterwards. A stock or price change on a variant must result in its product being reindexed.
