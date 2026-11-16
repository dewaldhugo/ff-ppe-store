# Phase 2: Vendor ingestion (VendorSync)

Read `CLAUDE.md` in full before starting. For this phase the scope is inverted: the ingestion area is in scope (see "Ingestion phases" in CLAUDE.md) and the storefront is out of scope, apart from the touchpoints listed below.

## Goal

Automated, scheduled, idempotent import of both vendors into Lunar and the vendor tables, in exactly the shape the data contract defines. After this phase the store runs on real vendor data with real images, and the fixture seeder is only used in tests.

## Inputs

- `tests/Fixtures/VendorSync/SafetyJogger/product-284096.json`: authenticated `GET /v1/product/{id}` response (Ligero, three colours, GBP prices).
- `tests/Fixtures/VendorSync/SafetyJogger/product-271323.json`: unauthenticated response (SX310, no prices, no descriptions). Use it to test missing-data handling.
- `tests/Fixtures/VendorSync/SafetyJogger/product-279074.json` (TACTIC: high-cut occupational boot, OB, EN ISO 20347) and `product-215754.json` (BESTBOOT: high safety boot, S3, EN ISO 20345, steel toecap).
- `tests/Fixtures/VendorSync/SafetyJogger/product-list.json`: 7 items from a real `GET /v1/product` (463 products in full): Ligero, TACTIC, BESTBOOT, an item with RRP 0, a Safety Workwear garment, a Safety Jogger Adventure (non-PPE) shoe and an Oxypas shoe.
- `tests/Fixtures/VendorSync/SafetyJogger/stock.json`: rows from a real `GET /v1/stock` (7,024 rows in full) covering every SKU of Ligero, TACTIC and BESTBOOT, plus two rows sharing one EAN, a row without an EAN and a row with an empty `stockType`.
- `tests/Fixtures/VendorSync/Footsure/footsure.csv`: a real daily export (201 rows, 50 families).
- Safety Jogger API: OpenAPI spec at `https://ws.safetyjogger.com/v1/openapi.json`. Use production, `https://ws.safetyjogger.com/v1`: the test environment returns no prices. The client is read-only by construction (see the endpoint allowlist), so development against production is safe.

## Architecture

All code under `app/Domain/VendorSync`, config in `config/vendorsync.php`, migrations named `*_vendorsync_*`, tests under `tests/Feature/VendorSync` and `tests/Unit/VendorSync`.

Pipeline per vendor: **Source** (fetch raw records) -> **raw store** (payload plus hash) -> **Mapper** (raw to normalised DTOs) -> **Normalisers** (shared rules) -> **Writer** (Lunar and vendor tables) -> **batch reindex** -> **image jobs**.

1. `Contracts\VendorSource` and `Contracts\VendorMapper` interfaces, one implementation per vendor. Adding a third vendor must not touch the Writer.
2. Readonly DTOs: `NormalisedProduct`, `NormalisedVariant`, `NormalisedImage`. Every contract field is represented; unknown values are null, never guessed.
3. One `CatalogueWriter` used by both vendors. It is the only class that writes catalogue data.

## Safety Jogger source

- `SafetyJogger\ApiClient`: Laravel HTTP client. Authenticates with `POST /login` using `VENDORSYNC_SJ_USERNAME` (an email address) and `VENDORSYNC_SJ_PASSWORD`, against `VENDORSYNC_SJ_BASE_URL` (login and all data calls use the same base URL). Optional `VENDORSYNC_SJ_TOKEN` is used instead of the password when set. Requests a validity of 30 days. Credentials live in `.env` only, never in code, config defaults, logs or exception messages. Caches the hash until 1 hour before expiry; on a 401 it re-authenticates once and retries. Sends headers `username`, `secret`, `language: en`.
- **Endpoint allowlist.** The client exposes only: login, product list, product detail, stock. It must be impossible to call `/logout`, `DELETE /profile`, orders, addresses or the shopping cart through it. Add a test that asserts this.
- Throttle to 2 requests per second, retry with backoff on 429 and 5xx, timeout 30 s.
- Catalogue: `GET /product`, then `GET /product/{id}` for each product. Nightly.
- Stock: `GET /stock` in one call. Every 15 minutes, separate fast path (see Stock sync).
- Cost is `customerPrice` per SKU. Ignore `promoPrice` and `stockType` at launch.
- **Catalogue scope.** Import only products whose `articletype` is in `vendorsync.safety_jogger.article_types` (default `['shoes']`) **and** whose `norms` contain `EN ISO 20345` or `EN ISO 20347`. Everything else (workwear, gloves, overshoes, uncertified lifestyle footwear such as the Adventure range) is skipped and counted as `out_of_scope`, not drafted and not reported as an error. A brand exclusion list `vendorsync.safety_jogger.excluded_brands` (default empty) allows the client to remove whole brands.
- **Identity.** SKUs are identified by `skuId` (the `vendor_sku`), never by EAN: the real stock feed has duplicate EANs and SKUs without one.

## Footsure source

- Reads the newest `*.csv` from `VENDORSYNC_FS_INCOMING_PATH` (default `storage/app/vendorsync/footsure/incoming`). Delivery to that folder is handled outside this application.
- Validates the header row against the expected columns before processing. A missing or renamed column aborts the run with a clear error.
- Reads `File_Create_Date` (`m/d/Y H:i:s`). A file older than 36 hours aborts the run and alerts (stale feed).
- After a successful run, moves the file to `.../footsure/archive/` with a timestamp. Keeps 30 days of archives.
- Groups rows into products by `Family Product Reference`. One row is one variant. Barcodes are strings, never numbers.
- **Catalogue scope.** Footsure has one rule: import everything except Safety Jogger products, which come directly from the Safety Jogger API. Skip rows whose `Brand` matches `vendorsync.footsure.excluded_brands` (default `['Safety Jogger']`, case-insensitive, matching any brand that starts with an entry, so `Safety Jogger Works` is also skipped). Skipped rows count as `out_of_scope`. As a second guard, a Footsure variant whose EAN already belongs to a Safety Jogger variant is skipped and reported.

## Raw store and change detection

- Migration `vendorsync_create_vendor_raw_records_table`: `vendor_id`, `external_id`, `payload` (JSON), `payload_hash` (sha256 of the canonicalised payload), `fetched_at`, `last_seen_run_id`. Unique on (`vendor_id`, `external_id`).
- Migration `vendorsync_create_sync_runs_table`: `vendor_id`, `type` (`catalogue`, `stock`, `images`), `status` (`running`, `succeeded`, `failed`, `aborted`), `started_at`, `finished_at`, counts (`seen`, `created`, `updated`, `unchanged`, `drafted`, `errors`), `messages` (JSON, capped).
- A product whose payload hash is unchanged since the last successful run is skipped by the Writer (but still counts as seen). `--force` reprocesses everything.
- The raw payload keeps the 360-degree spin frame URLs, so a later spin feature needs no re-fetch.

## Normalisation rules (shared, unit-tested)

- **Safety class.** Remove spaces and hyphens (`S1 P` and `SB-P` become `S1P` and `SBP`). Where the vendor gives the base class and `P` separately (Footsure `Safety` = `SB` or `S1` plus `P` in `Safety Cat`), combine them: `SB` + `P` = `SBP`, `S1` + `P` = `S1P`. `S1P`, `S3`, `S3S` and the other classes stay as they are. `O`-classes follow the same rule for occupational footwear.
- **Features.** Map to the contract codes. Footsure `Waterproof = 1` adds `WR`. Safety Jogger benefit `NOMETAL` adds `METAL_FREE`. Unknown codes are dropped and reported, never invented.
- **Sizes.** Footsure supplies UK sizes directly. Safety Jogger supplies EU sizes only: convert using `size_charts.SJ` in `config/vendorsync.php`, taken from Safety Jogger's published chart (identical for men and women):
  `35 => 3, 36 => 3.5, 37 => 4, 38 => 5, 39 => 6, 40 => 6.5, 41 => 7, 42 => 8, 43 => 9, 44 => 10, 45 => 10.5, 46 => 11, 47 => 12, 48 => 13`.
  Jump sizes map pairwise: `35/36 => 3/3.5`, `37/38 => 4/5`, `39/40 => 6/6.5`, `41/42 => 7/8`, `43/44 => 9/10`, `45/46 => 10.5/11`, `47/48 => 12/13`.
  `49 => 13.5`. An EU size missing from the table puts the product in draft and reports it. Never guess a conversion.
  UK size strings are written without a trailing `.0` (`10`, not `10.0`), matching Footsure.
- **Colours.** Title case of the vendor's colour description; hex where supplied.
- **Categories.** A mapping in `config/vendorsync.php` from vendor category values (Footsure `Group Class`; Safety Jogger `articletype` plus `model`) to contract collection handles. An unmapped value creates the product as draft and reports it.
- **Tax class.** Footsure: VAT `0.2` gives `standard`; VAT `0` gives `zero-protective` for safety footwear and `zero-childrens` otherwise. Safety Jogger: `articletype` is `Shoes` for boots too, so it cannot identify boots. Use `model`: `zero-protective` only when the `model` name is in `vendorsync.safety_jogger.boot_models` (default `['high_boot', 'high']`) **and** `norms` contain `EN ISO 20345` (safety footwear with toe protection); everything else `standard`. So BESTBOOT (`high_boot`, S3, EN ISO 20345) is `zero-protective`; TACTIC (`high`, OB, EN ISO 20347 only) and Ligero (`low`) are `standard`.
- **Price.** *(Superseded by prompts/vendorsync-pricing-rules.md: the cap at the RRP was removed, markup is per vendor, non-GBP prices are never stored, and a price-change guard exists. See docs/vendorsync.md.)* Listing price ex VAT = cost x `markup` (config, default 1.5), rounded to the penny, capped at RRP ex VAT. `compare_price` = RRP ex VAT when higher than the price. Footsure `SRP` is VAT-inclusive at the row's VAT rate; Safety Jogger `rrpPrice` is VAT-inclusive at 20% for standard-rated goods. When RRP is missing or 0 (92 of the 463 real products), there is no cap and `compare_price` is null. A variant with cost greater than or equal to a non-zero RRP, or with no cost, is set to stock 0 and reported.
- **Gender.** Footsure `Gender` maps to `Men`, `Women`, `Unisex`. Safety Jogger defaults to `Unisex`.
- **Country of origin.** ISO alpha-2 (Safety Jogger supplies it; map Footsure country names through a table, unknown names reported).
- **Stock buffer.** Sellable stock = vendor stock minus `stock_buffer` per vendor (config: SJ 0, FS 1), floored at 0.

## Writer rules

- Resolve every contract handle through `CatalogueKeys`. Never match on names.
- Identity is (`vendor`, `vendor_product_id`) for products and (`vendor`, `vendor_sku`) for variants, through `vendor_products` and `vendor_offers`. Never on name or slug.
- **Create:** product, URL via `GenerateProductSlug` (only on create), brand (`firstOrCreate` by name), collections, options and shared option values (reuse the existing value lookup used by `FixtureCatalogueSeeder`), variants, prices, vendor rows, cross-sell associations.
- **Update:** vendor-owned fields are overwritten on every change: prices, cost, stock, EAN, dimensions, weight, tax class, safety class, features, standards, materials, collections from the category map, vendor table rows. Content fields (`name`, `title`, `short_description`, `description`, `key_points`) are updated only when the vendor value changed **and** the field is not locked.
- **Field locks.** Migration `vendorsync_create_vendor_field_locks_table`: `product_id`, `field`. A locked field is never overwritten. (Admin UI for locks is a later phase; locks can be added with tinker for now.)
- **Lifecycle.** Footsure `Live` = published; `Clearance` = published plus `clearance` collection; `No More` = draft. Colour status `No More` sets that colour's variants to stock 0. Safety Jogger: products in the product list are published (unless drafted by a rule above).
- **Discontinuation.** A product absent from **two consecutive successful** full runs is set to draft and its variants to stock 0. Never delete anything.
- **Safety threshold.** If a run sees fewer than 70% of the products seen in the previous successful run, abort before writing anything and alert.
- **Cross-vendor duplicates.** If a new variant's EAN already belongs to another vendor's variant, do not create a duplicate: report it. Merging is a later phase.
- All writes in a run happen inside `withoutSyncingToSearch()`, then the affected products are reindexed in one batch.
- `--dry-run` runs everything except the writes and prints the counts and a sample of changes.

## Stock sync (fast path)

- Safety Jogger only: `GET /stock` every 15 minutes. Matches rows on `skuId`, never EAN. Updates `vendor_offers.stock` and the Lunar variant `stock` (with buffer) using bulk updates, without loading products. Rows for SKUs that are not imported are ignored. The stock feed is fresher than the stock inside the product detail, so the stock sync always wins.
- Reindexes only products whose in-stock flag, in-stock colours or in-stock sizes changed.
- Footsure stock is updated by its daily catalogue run.

## Images

- Queued job per product, after the catalogue run. Downloads into the `products` disk through Media Library, with custom properties `colour` and `role`, and links each variant to its colour's images (`main` as primary), exactly as `FixtureCatalogueSeeder` does.
- Safety Jogger: per colour, `images.hires` is an array. Its first entry (`...-HIRES.PNG`) is role `main`, the `HR01`, `HR02`, ... entries are role `detail`, and `images.outsole` is role `outsole`. If `hires` is empty, fall back to `images.web` (first entry `main`, the rest `detail`), then to `images.main`. Skip the `360` frames.
- Footsure: `Image1` is `main`; `Image2` to `Image8` are `detail`. Try `https` first and fall back to `http`.
- Change detection: store the source URL and the content sha256 as custom properties. Re-download only when the URL set changed, or on a weekly full image refresh. Identical content is never stored twice.
- Throttle per vendor host. A failed download is retried three times, then reported; the product keeps its other images.
- Products without any image stay draft until at least one image exists.

## Commands and schedule

- `vendorsync:catalogue {vendor} {--dry-run} {--force}`
- `vendorsync:stock {vendor}`
- `vendorsync:images {vendor} {--product=} {--refresh}`
- `vendorsync:status`: last run per vendor and type, with counts and errors.
- Schedule (Europe/London): SJ catalogue 02:15 daily, SJ stock every 15 minutes, FS catalogue 07:20 daily (the export is created around 06:05), images after each catalogue run. All `withoutOverlapping()` and `onOneServer()`. The schedule only registers when `VENDORSYNC_SCHEDULE_ENABLED=true` (default false), so a developer machine never polls the production API on its own.
- Alerts: failed, aborted or stale runs send a mail notification to `VENDORSYNC_ALERT_EMAIL` and log at error level.

## Storefront touchpoints (allowed in this phase)

- Update `database/fixtures/catalogue.json` safety classes: FS9 becomes `SBP`, FS65 becomes `S1P`. Also correct the Safety Jogger `shoe_size` values in the fixture to the size chart above (the fixture used a provisional table: EU 44 is UK 10, not 9.5). Update the Phase 1 test that expects S1P plus SRC to return Ligero only: it now returns Ligero and FS65.
- `DatabaseSeeder` may call the vendor importers in local environments when `VENDORSYNC_SEED_FROM_FIXTURES=true`, importing the test fixtures through the real pipeline.
- Nothing else in the storefront changes.

## Acceptance criteria

- Importing the Footsure fixture CSV twice: the second run reports all products unchanged and changes no rows (compare `updated_at`).
- Importing the Ligero fixture produces the same Lunar shape as `FixtureCatalogueSeeder` does for Ligero (same attributes, options, variants, prices, tax class, collections, vendor rows), apart from slug suffixes and images.
- FS9 is `SBP`, FS65 is `S1P`, FS30C is `zero-protective`, the children's plimsolls are `zero-childrens`.
- BESTBOOT is `zero-protective` with UK sizes 3.5 to 12; TACTIC and Ligero are `standard`.
- Importing the product list fixture imports Ligero, TACTIC and BESTBOOT and skips the workwear and Adventure items as `out_of_scope`. The four list items without a detail fixture get minimal faked detail responses in the test (Oxypas: shoes with EN ISO 20347, imported; workwear: no `Shoes` article type; Adventure: shoes without an EN ISO norm; the RRP 0 item: shoes with EN ISO 20345 and no RRP, imported uncapped).
- The stock fixture updates every Ligero, TACTIC and BESTBOOT offer by `skuId`, and the duplicate-EAN rows do not affect each other.
- A product removed from the feed is drafted after the second consecutive run, not the first, and never deleted.
- A CSV with a renamed column, a stale `File_Create_Date`, or 50% fewer families aborts without writing.
- A locked `description` survives an import with a changed vendor description.
- The API client cannot reach any endpoint outside the allowlist.
- An unmapped category, an unknown EU size, and a cost above RRP each produce a draft or stock 0 plus a report entry, never a guessed value.
- The stock sync updates 1,000 offers in under 5 seconds locally and reindexes only changed products.
- All HTTP is faked in tests (`Http::fake`); no test calls a real vendor.
- Pint and Pest pass, including all Phase 1 tests (with the updated S1P expectation).

## Out of scope

Admin UI for runs and locks, certificate PDF mirroring, 360-degree spin frames, cross-vendor merging, vendor API ordering, real production credentials.
