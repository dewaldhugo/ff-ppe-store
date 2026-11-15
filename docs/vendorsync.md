# Vendor ingestion (VendorSync) reference

Scope rules for who may edit what are in CLAUDE.md (Scope boundary, Ingestion phases). This file holds the rules and vendor table definitions.

## Ingestion rules (summary, verified against `config/vendorsync.php`)

- **Safety Jogger.** Scope: `articletype` in `['shoes']` and an EN ISO 20345 or 20347 norm; `excluded_brands` is empty. `zero-protective` only for models `high_boot` and `high` with EN ISO 20345; `low_boot` stays standard-rated until the accountant confirms. Stock buffer 0, and the 15 minute stock feed is the stock authority. SKUs are identified by `skuId`, never by EAN. RRP includes VAT at 20%.
- **Footsure.** Imports everything except brands starting with `Safety Jogger` (case-insensitive). Brand `Miscellaneous Other` maps to no brand. An unmapped `Group Class` drafts the product. Stock buffer 1, stock comes from the daily catalogue run. Placeholder measurements (`999`, `333`, `222`, `111`) are stored as null. A feed file older than 36 hours aborts the run; one dated more than 60 minutes ahead is unreadable.
- **Run safety.** A run that sees fewer than 70% of the previous run's products aborts before writing. A product is drafted after 2 missed runs and never deleted. A run holds its lock for 4 hours. The schedule only registers when `VENDORSYNC_SCHEDULE_ENABLED=true`. Failed, aborted and stale runs mail `VENDORSYNC_ALERT_EMAIL`.
- **Images.** Retries 3, timeout 60 s, 250 ms throttle per host, weekly refresh. Images above 5 megapixels are replaced by a smaller rendition or reported (GD memory). Files above 20 MB are rejected. A product with no image stays draft.
- **Commands.** `vendorsync:catalogue`, `vendorsync:stock`, `vendorsync:images`, `vendorsync:status`.

## Vendor tables (owned by the ingestion module)

Vendor identity and ordering data live here, not in Lunar.

- `vendors`: `id`, `code` (unique, e.g. `SJ`, `FS`), `name`.
- `vendor_products`: `product_id` (Lunar product), `vendor_id`, `vendor_product_id`, `commodity_code` (UK tariff code, may be null). Unique on (`vendor_id`, `vendor_product_id`). Non-unique index on `product_id`. For Safety Jogger `vendor_product_id` is the API product `id`; for Footsure it is the `ProductID` column (each `Family Product Reference` is one colourway of that product).
- `vendor_offers`: `product_variant_id` (Lunar variant), `vendor_id`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean` (may be null), `cost_price` (pence ex VAT, may be null), `stock`, `last_seen_at`. Unique on (`vendor_id`, `vendor_sku`). Non-unique index on `product_variant_id`. The `vendor_*` values are the vendor's own wording, used to place orders manually.
- The same Lunar product or variant may be supplied by more than one vendor, so neither table is unique on the Lunar ID. At launch each variant has one offer.
- Cost is never derived from the selling price. It comes only from `vendor_offers.cost_price`.
- The ingestion module adds further tables and columns through new `*_vendorsync_*` migrations: tables `sync_runs`, `vendor_raw_records` and `vendor_field_locks`; columns on `vendor_products` (`payload_hash`, `content_hashes`, `draft_reasons`, `related_ids`, `missed_runs`, `image_urls_hash`, `images_synced_at`) and `vendor_offers` (`sellable`). The storefront never depends on them.
