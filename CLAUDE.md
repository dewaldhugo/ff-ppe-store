# CLAUDE.md

## Project

UK ecommerce store selling personal protective equipment (PPE) to UK customers in GBP. Products are dropshipped from two UK vendors (Safety Jogger, Footsure). Safety footwear is the launch category; workwear, gloves and head protection will follow.

Stack: PHP 8.4, Laravel 13, Lunar 1.5 (headless commerce, with its Filament 4 admin panel), MySQL 8, Redis, Laravel Horizon, Meilisearch via Laravel Scout, Livewire 3 and Tailwind CSS 4 (Vite 7) for the storefront, Spatie Media Library for images, Pest and Pint for tests and style. Hosted on a manually configured Debian 13 VPS (no Forge): this repository does not automate deployment.

## Local environment (Windows)

- Windows 11, Laravel Herd (free), PHP 8.4 set as Herd's global version, site served at http://ppe-store.test.
- MySQL 8.4 LTS runs natively (same major version as production): databases `ppe_store` and `ppe_store_testing`, user `ppe`.
- Meilisearch 1.56.2 runs natively at http://localhost:7700 with a master key (same version as production).
- No Redis locally. Use `QUEUE_CONNECTION=database`, `CACHE_STORE=database` and `SESSION_DRIVER=database` in `.env`. Production uses Redis and Horizon, so code must work with both drivers: never call Redis directly.
- Horizon cannot run on Windows (no pcntl). Install it, configure it for production, and use `php artisan queue:work` locally. `composer.json` fakes `ext-pcntl` and `ext-posix` under `config.platform` so Horizon installs on Windows: do not remove it.
- MySQL, Meilisearch, Redis and the server OS are operator-maintained: the repository cannot verify their versions.
- Shell commands run in Git Bash on Windows. Avoid Unix-only tooling in scripts and composer hooks.

## Scope boundary (read this first)

This repository contains two areas with different owners:

1. **Storefront and commerce** (your scope): catalogue, product pages, search and filtering, cart, checkout, customer accounts, admin customisation, emails.
2. **Vendor ingestion** (NOT your scope): `app/Domain/VendorSync/**`, `config/vendorsync.php`, `database/migrations/*_vendorsync_*`, and their tests (`tests/Feature/VendorSync`, `tests/Unit/VendorSync`, `tests/Fixtures/VendorSync`, `tests/Support/VendorSync.php`). The module is wired in by one line in `bootstrap/providers.php` (`VendorSyncServiceProvider`) and reads `database/fixtures/catalogue.json` through the fixture seeder. This module imports vendor data into Lunar and is developed separately.

Rules:
- Never create, edit or delete files inside the ingestion area.
- Never write code that calls vendor APIs or reads vendor feeds.
- Assume the Lunar database is populated correctly according to the data contract below. If the storefront needs data the contract does not provide, stop and report it rather than inventing a workaround.
- The storefront reads catalogue data from Lunar models only. It must not read the `vendors`, `vendor_products` or `vendor_offers` tables. The one permitted read is the order line snapshot at checkout (see Orders and fulfilment). Admin reporting may read them where a task explicitly says so.
- The three Phase 1 `*_vendorsync_*` migrations (`vendors`, `vendor_products`, `vendor_offers`) were created so the storefront and fixtures can use the tables. They belong to the ingestion module: never modify them. Later `*_vendorsync_*` migrations also belong to the ingestion module.
- The fixture seeder (local and testing only) is the only storefront code that writes to the vendor tables. `DatabaseSeeder` may also call the ingestion importer locally when `VENDORSYNC_SEED_FROM_FIXTURES=true`.

## Ingestion phases

When the current phase prompt is an ingestion phase (prompts/phase-2-vendorsync.md or a later vendorsync prompt), the scope is inverted: the ingestion area (app/Domain/VendorSync, config/vendorsync.php, *_vendorsync_* migrations, tests/*/VendorSync, tests/Fixtures/VendorSync, tests/Support/VendorSync.php) is in scope and may be created and changed, and the storefront is out of scope except for the touchpoints the phase prompt lists (for Phase 2: `database/fixtures/catalogue.json`, `DatabaseSeeder`, the `bootstrap/providers.php` line). Existing vendorsync migrations remain unchanged; new tables and columns go in new migrations. The data contract still applies in full, and the importer must write exactly the shape the fixture seeder writes.

### Ingestion rules (summary, verified against `config/vendorsync.php`)

- **Safety Jogger.** Scope: `articletype` in `['shoes']` and an EN ISO 20345 or 20347 norm; `excluded_brands` is empty. `zero-protective` only for models `high_boot` and `high` with EN ISO 20345; `low_boot` stays standard-rated until the accountant confirms. Stock buffer 0, and the 15 minute stock feed is the stock authority. SKUs are identified by `skuId`, never by EAN. RRP includes VAT at 20%.
- **Footsure.** Imports everything except brands starting with `Safety Jogger` (case-insensitive). Brand `Miscellaneous Other` maps to no brand. An unmapped `Group Class` drafts the product. Stock buffer 1, stock comes from the daily catalogue run. Placeholder measurements (`999`, `333`, `222`, `111`) are stored as null. A feed file older than 36 hours aborts the run; one dated more than 60 minutes ahead is unreadable.
- **Run safety.** A run that sees fewer than 70% of the previous run's products aborts before writing. A product is drafted after 2 missed runs and never deleted. A run holds its lock for 4 hours. The schedule only registers when `VENDORSYNC_SCHEDULE_ENABLED=true`. Failed, aborted and stale runs mail `VENDORSYNC_ALERT_EMAIL`.
- **Images.** Retries 3, timeout 60 s, 250 ms throttle per host, weekly refresh. Images above 5 megapixels are replaced by a smaller rendition or reported (GD memory). Files above 20 MB are rejected. A product with no image stays draft.
- **Commands.** `vendorsync:catalogue`, `vendorsync:stock`, `vendorsync:images`, `vendorsync:status`.

## Data contract

The ingestion module writes data in exactly this shape. The storefront depends on it. Changing it requires updating both sides, so treat it as fixed.

### Channel, currency, tax
- One channel, handle `webstore`.
- One currency, `GBP`, default, 2 decimal places. Prices are integers in pence.
- One customer group, handle `retail`, default.
- Prices are stored **exclusive of VAT** (`lunar.pricing.stored_inclusive_of_tax = false`). The same product can carry different VAT for different customers (see VAT below), so a single VAT-inclusive price cannot be stored.
- Tax zone `uk` (default zone). Three tax classes, identified by handle through the key registry (see Contract handles), because Lunar tax classes have no handle column:
  - `standard`: 20%.
  - `zero-protective`: protective **boots** (not shoes) and helmets for industrial use. 0% when sold to an individual for their own use, 20% when sold to an employer for use by employees (HMRC VAT Notice 701/23).
  - `zero-childrens`: children's footwear, 0%.
- Listing price (ex VAT) = vendor cost x 1.5, capped at the vendor RRP (ex VAT). Calculated by the ingestion module. The storefront never recalculates it.

### VAT

- Specified, not yet built (checkout is a placeholder page): the storefront asks at checkout whether the customer is buying for employees. If yes, `zero-protective` lines are charged at 20%; otherwise at 0%. Store the answer on the order (meta `buying_for_employees`). Until then the product page only flags that VAT may apply for employers.
- The storefront displays, sorts and filters every price ex VAT, with an `ex VAT` suffix (`DisplayPrice::SUFFIX`). There is no inc/ex VAT toggle: the store sells to business buyers. Structured data states each offer ex VAT explicitly (`priceSpecification` with `valueAddedTaxIncluded: false`).
- The compare price is the manufacturer's RRP, ex VAT, and is always labelled as that: never a bare struck-through amount.
- In Lunar the `zero-protective` class carries the individual-use rate (0%). The 20% employer rate is to be applied by the tax driver at checkout (not yet built), not by a second tax class.

### Contract handles (key registry)

Lunar has no handle column on tax classes, product types, tax zones or collections, and their names are editable in the admin. The contract identifies these records by handle, so handles are held in an app-owned registry. It belongs to the storefront, not the ingestion area.

- Table `catalogue_keys`: `id`, `key_type` (string), `handle` (string), `model_type` (string), `model_id` (unsigned big int), timestamps. Unique on (`key_type`, `handle`); indexed on (`model_type`, `model_id`).
- `key_type` values: `tax_class`, `product_type`, `collection`, `tax_zone`. Use it for any other Lunar record the contract identifies by handle where Lunar has no native handle column.
- Records with native handles use them directly: attributes, product options, channels, customer groups, collection groups, currencies (by code), languages (by code).
- `CatalogueFoundationSeeder` creates each Lunar record and its `catalogue_keys` row together, idempotently: look up by key first, create only if missing, never overwrite admin-edited names.
- Service `App\Domain\Catalogue\Keys\CatalogueKeys` resolves a handle to a model, e.g. `CatalogueKeys::taxClass('zero-protective')`, `::productType('safety-footwear')`, `::collection('safety-boots')`, `::taxZone('uk')`, and the reverse lookup `CatalogueKeys::handleFor(KeyType, Model)`. Results are cached and the cache is flushed when the seeder runs. An unknown handle throws a clear exception.
- The storefront, the fixture seeder and the ingestion module all resolve contract handles through this service only. Never match on name.

### Product types (attribute sets)
- `safety-footwear` (launch category)
- `workwear`, `gloves`, `head-protection` (created now, unused until later)

### Product attributes (handles are fixed)

| Handle | Type | On product types | Notes |
|---|---|---|---|
| `name` | TranslatedText | all | Customer-facing product name |
| `title` | TranslatedText | all | Descriptive title, e.g. "Lightweight ESD Safety Shoes" |
| `short_description` | TranslatedText | all | One or two sentences |
| `description` | TranslatedText | all | Long description, plain text with paragraphs |
| `safety_class` | Text | safety-footwear | Normalised, no spaces: `SB`, `SBP`, `S1`, `S1P`, `S2`, `S3`, `S7`, `OB`, `O1`, `O2`... The EN ISO 20345:2022 classes `S1PS`, `S3S` and `S7S`, the occupational class `O6`, and the EN ISO 20346 protective class `PB` are valid values. |
| `features` | ListField | safety-footwear | Normalised codes: `SRA`, `SRB`, `SRC`, `SR`, `ESD`, `CI`, `HI`, `HRO`, `WR`, `FO`, `M`, `AN`, `E`, `A`, `P`, `LG` (ladder grip), `SC` (scuff cap), `WRU` (water resistant upper), `METAL_FREE` |
| `standards` | ListField | all | e.g. `EN ISO 20345:2011`, `EN ISO 20345:2022`, `ASTM F2413:2024` |
| `toecap` | Text | safety-footwear | e.g. `Nano Carbon`, `Steel`, `Composite`, `None` |
| `midsole` | Text | safety-footwear | |
| `outsole` | Text | safety-footwear | |
| `upper` | Text | safety-footwear | |
| `lining` | Text | safety-footwear | |
| `cut` | Text | safety-footwear | `Low`, `Mid`, `High` |
| `industries` | ListField | all | e.g. `Automotive`, `Logistics`, `Construction` |
| `gender` | Text | all | `Men`, `Women`, `Unisex` |
| `key_points` | ListField | all | Short benefit bullets, 3 to 10 items |
| `fastening` | Text | safety-footwear | e.g. `Laces`, `Slip On`, `Touch Fastening`, `Zip` |
| `country_of_origin` | Text | all | ISO 3166-1 alpha-2, e.g. `RO`, `ES` |
| `video_url` | Text | all | Optional YouTube embed URL |
| `certificates` | ListField | all | Optional, URLs of local copies of conformity certificate PDFs |

Waterproofing is the `WR` feature, not a separate attribute. Which attributes are searchable and filterable is defined under Search index below.

### Variant attributes

| Handle | Type | On product types | Notes |
|---|---|---|---|
| `eu_size` | Text | safety-footwear | EU size shown as secondary text next to the UK size, e.g. `42` |

### Product options
- `colour`: values are customer-facing colour names, title case (`Navy`, `Black`). Each value has meta `hex` (e.g. `394656`, may be null).
- `shoe_size`: values are **UK sizes** as strings (`3`, `3.5`, ... `13.5`), with meta `sort` (numeric) for ordering. The EU size is the variant attribute `eu_size`.
  - Size labels follow the size scale, never the VAT rate. A row whose EU size is below 33 is on the infant scale and its value is `Kids {UK size}` (e.g. `Kids 6`). Every other row uses the plain UK size (`1`, `2`, `6`), whether zero rated or not: junior and adult sizes from UK 1 upward are one physical scale. A zero rated row with no EU size is skipped as a variant and reported.
  - `sort` is the EU size, for both vendors, so the options order correctly (`Kids 6` = 23, `1` = 33, `6` = 39). A size with no EU size sorts by the vendor's chart for that UK size.
- `garment_size`: reserved for workwear (`XS` ... `4XL`). Unused at launch.

Every safety-footwear variant has exactly one `colour` value and one `shoe_size` value.

### Variants
- `sku` = `{VENDOR_CODE}-{vendor_sku}`, e.g. `SJ-1492552`, `FS-00052-00095-09`.
- `ean` set when known (may be null), in Lunar's native variant column.
- `stock` = sellable quantity after the ingestion module applies its safety buffer. `purchasable = 'in_stock'`.
- `shippable = true`. Tax class is one of the three above, set by the ingestion module.
- Dimensions and weight use Lunar's native variant fields when the vendor supplies them (weight in kg; length, width and height of the box in mm). They may be null.
- Lunar variants have no meta column. Vendor identity and the vendor's own wording live in `vendor_offers` (see Vendor tables), not on the variant.
- One price per variant in GBP for customer group `retail`, ex VAT. `compare_price` = vendor RRP ex VAT when it is higher than the price, otherwise null.

### Products
- `status`: `published` or `draft`. Discontinued products are set to `draft`, never deleted, because orders reference them.
- `brand`: Lunar Brand (e.g. `Safety Jogger`, `Maxguard`, `Amblers Safety`, `GBS`). May be null: vendor placeholder brands (Footsure `Miscellaneous Other`) are mapped to no brand by the ingestion module and are never shown to customers.
- Lunar products have no meta column. `vendor_code`, `vendor_product_id` and `commodity_code` live in `vendor_products` (see Vendor tables).
- Related products are Lunar's native product associations of type `cross-sell`. The writer resolves the vendor's related product IDs to Lunar products and creates an association for each one that exists.
- Products the vendor marks as clearance are added to the `clearance` collection in the `ranges` group.
- URLs: Lunar URL records, default language `en`. The product slug is `Str::slug(brand . ' ' . name)`, e.g. `safety-jogger-ligero`. On collision a numeric suffix is appended (`-2`, `-3`, ...) in order of creation. This rule is implemented once, in the action `App\Domain\Catalogue\Urls\GenerateProductSlug`, which the fixture seeder and the ingestion module both call. Lunar's automatic URL generator is disabled. A product's slug is set once when the product is created and is never changed afterwards, including by the ingestion module when a vendor renames a product. Renamed products keep their original URL.

### Collections
- Collection group `categories`: the customer-facing category tree, e.g. Safety Footwear > Safety Trainers, Safety Boots, Safety Shoes; Occupational Footwear; Slippers; Plimsolls; Casual Footwear; Accessories; Wellingtons. Products belong to one or more leaf collections.
- Contract handles in `categories`: `safety-footwear` (parent of `safety-trainers`, `safety-shoes`, `safety-boots`), `occupational-footwear`, `slippers`, `plimsolls`, `casual-footwear`, `accessories`, `wellingtons`. In `ranges`: `clearance`.
- Collection group `ranges`: marketing collections (New in, Clearance, Industry ranges). Managed in the admin.
- Collections the contract names (the category tree and `clearance`) are resolved by handle through the key registry. Each also has a Lunar URL whose slug equals its handle.

### Media
- Spatie Media Library, disk `products`, collection `images`, attached to the product.
- Each image has custom property `colour` (the colour option value it depicts) and `role` (`main`, `side`, `outsole`, `detail`). Variants are linked to their colour's images through Lunar's variant image association, with the `main` image as primary.
- Conversions: `thumb` (300), `medium` (800), `large` (1600), WebP.
- 360-degree spin frames are not imported at launch. A separate table will be added later.

### Orders and fulfilment

Specified, not yet built: there is no order snapshot code and no admin fulfilment view in the repository yet (checkout is a placeholder page).

Orders are placed with vendors manually at launch (no vendor API ordering). When an order is created, the storefront copies from the variant's `vendor_offers` row (and its vendor) onto each order line's meta: `vendor_code`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean`, and `cost_price` (pence, ex VAT, at the time of the order, may be null). This is the one permitted storefront read of the vendor tables. The snapshot is the record of what was ordered from the vendor and must not change when vendor data changes later. The admin needs a per-vendor view of unfulfilled order lines showing product name, colour, size and quantity, with CSV export.

### Vendor tables (owned by the ingestion module)

Vendor identity and ordering data live here, not in Lunar.

- `vendors`: `id`, `code` (unique, e.g. `SJ`, `FS`), `name`.
- `vendor_products`: `product_id` (Lunar product), `vendor_id`, `vendor_product_id`, `commodity_code` (UK tariff code, may be null). Unique on (`vendor_id`, `vendor_product_id`). Non-unique index on `product_id`. For Safety Jogger `vendor_product_id` is the API product `id`; for Footsure it is the `ProductID` column (each `Family Product Reference` is one colourway of that product).
- `vendor_offers`: `product_variant_id` (Lunar variant), `vendor_id`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean` (may be null), `cost_price` (pence ex VAT, may be null), `stock`, `last_seen_at`. Unique on (`vendor_id`, `vendor_sku`). Non-unique index on `product_variant_id`. The `vendor_*` values are the vendor's own wording, used to place orders manually.
- The same Lunar product or variant may be supplied by more than one vendor, so neither table is unique on the Lunar ID. At launch each variant has one offer.
- Cost is never derived from the selling price. It comes only from `vendor_offers.cost_price`.
- The ingestion module adds further tables and columns through new `*_vendorsync_*` migrations: tables `sync_runs`, `vendor_raw_records` and `vendor_field_locks`; columns on `vendor_products` (`payload_hash`, `content_hashes`, `draft_reasons`, `related_ids`, `missed_runs`, `image_urls_hash`, `images_synced_at`) and `vendor_offers` (`sellable`). The storefront never depends on them.

### Search index

One Meilisearch document per product, written by Lunar's product indexing through the storefront's custom indexer (`config/lunar/search.php`, `indexers`). Drafts are indexed too because the admin panel searches the same index; the storefront always filters on `status = published`.

- Searchable: `name`, `title`, `brand`, `key_points`, `safety_class`, `standards`, `skus` (so the admin panel finds products by SKU).
- Filterable: `collection_ids` (the product's collections and all their ancestors), `brand`, `safety_class`, `features`, `gender`, `standards`, `cut`, `toecap`, `colours_in_stock`, `sizes_in_stock`, `variants_in_stock` (colour and size pairs, e.g. `navy|9`), `in_stock` (bool), `price_min_ex_vat`, `price_min_inc_vat`, `status`. Prices are in pence.
- Sortable: `price_min_inc_vat`, `price_min_ex_vat`, `created_at`, `sort_order`.
- `price_min_inc_vat` (individual-use rate) is indexed but not used by the storefront, which filters and sorts on `price_min_ex_vat` only. Nothing in the storefront, commands, tests or Lunar's admin code reads it.
- Index settings are applied by the versioned command `php artisan catalogue:sync-search-settings`, run on every deploy. Never change them by hand.
- Listings and facets query Meilisearch only, through `App\Domain\Catalogue\Search\CatalogueSearch`. No direct Eloquent filtering for listings.
- `SCOUT_QUEUE=true` in production, `false` locally and in tests.
- Bulk writers (the ingestion module, seeders, imports) must wrap their writes in `withoutSyncingToSearch()` and then reindex the affected products in one batch afterwards. A stock or price change on a variant must result in its product being reindexed.

## Conventions

- PHP `^8.4` and constructor property promotion. Strict types and `final` classes are enforced by Pint (`pint.json`); `config/` and abstract classes are exempt.
- Domain code under `app/Domain/{Area}`, Livewire components under `app/Livewire/{Area}`. Small cross-cutting helpers live in `app/Support`; Blade components under `resources/views/components/{ui,home,storefront,layouts}`.
- Money is never a float. Use Lunar's `Price` and `Money` objects or integers in pence.
- Use Lunar's models, actions, pipelines and events. Do not duplicate cart, pricing, tax or order logic that Lunar already provides. If Lunar cannot do something, explain why before writing custom logic.
- Pest for tests, in `tests/Feature/{Catalogue,Pricing,Storefront,VendorSync}` and `tests/Unit`. Every feature ships with feature tests. Use the fixture seeder, never live vendor data.
- Run `./vendor/bin/pint` and `./vendor/bin/pest` before declaring a task done.
- No new composer or npm packages without stating why and asking first.
- UK English in all customer-facing copy (colour, catalogue, favourite). Never use em dashes in copy, comments or docs.

## URLs

Current routes (`routes/web.php`):

| Route name | URI | Target |
|---|---|---|
| `home` | `/` | view `home` |
| `products` | `/products` | view `pages.products` |
| `category` | `/c/{slug}` | `CategoryListing` (the collection URL slug, which equals its contract handle) |
| `search` | `/search` | `CategoryListing` |
| `product` | `/p/{slug}` | `ProductPage` |
| placeholders | `/about`, `/contact`, `/account`, `/cart`, `/checkout`, `/terms`, `/privacy`, `/cookies`, `/returns`, `/delivery`, `/accessibility` | `pages.placeholder` (noindex) |

Always link with `route()`; the canonical product URL comes from `route('product', $slug)`. Never hard-code paths in views or tests.

## Storefront

- **Design tokens.** Colours are tokens in the `@theme` block of `resources/css/app.css` (`brand-blue`, `brand-teal`, `brand-navy`, `ink`, `ink-muted`, `surface-muted`, `white`, `border`, `danger`, `success` and the disabled pair). Templates use only these tokens. `tests/Feature/Storefront/DesignTokensTest.php` reads the CSS and requires WCAG AA contrast (4.5:1) for every text and background pair used.
- **Buttons.** `x-ui.button` has two variants: primary (`brand-blue` fill, white text) and `accent` (`brand-teal` fill, `brand-navy` text), which is only for navy surfaces. `x-ui.button-outline` is the secondary button (`on-dark` for navy surfaces).
- **Noindex.** One flag, `STORE_NOINDEX` (`config/store.php`, default true), puts `<meta name="robots" content="noindex">` on every page. Placeholder pages also carry their own noindex. See the Launch checklist.
- **Config-driven home page.** `config/homepage.php` holds the "Shop by standard" chips (`standards`) and the trust strip claims (`trust`; an empty slot is not rendered).
- **Store identity.** `STORE_*` values in `.env` feed `config/store.php` and `App\Support\Store`; each reads `TBC` until it is known.

## Workflow

- Work in phases. Each phase prompt lists its deliverables and acceptance criteria. Do not start work outside the current phase.
- Before writing code for a phase, produce a short plan and list any assumption about the data contract.
- Verify package versions and APIs against current documentation (composer, Lunar docs) rather than relying on memory.
- Commit at the end of each completed step with a conventional commit message.

## Later

Known follow-ups, not in the current phase:

- Merge vendor products split by size band into one Lunar product with several `vendor_products` rows. Footsure lists one line as several ProductIDs (Morris, Dunlop Sport, 6045/PSG27 plimsolls, Audrey Slipper, Primera Gardening Shoe); each is its own product today, with numeric slug suffixes.

## Launch checklist

Do these before the store goes live. Nothing here is done by default.

- [ ] Lift noindex: set `STORE_NOINDEX=false` in the production environment. One flag drives every page (`config/store.php`). A placeholder page also carries its own noindex: remove the `:noindex="true"` from `resources/views/pages/placeholder.blade.php` for a page once it has real content, or give the page its own view.
- [ ] Verify every homepage "Shop by standard" chip returns products on real data (the chips are in `config/homepage.php`; the tests only prove it against the fixture catalogue).
- [ ] Confirm the trust strip claims with the client (`config/homepage.php`, `trust`). The fourth slot is for a delivery claim that is not confirmed yet: leave it empty until it is.
- [ ] Confirm `pcntl` and `posix` are present in the server's PHP CLI before starting Horizon (`composer.json` fakes them under `config.platform` for Windows, so Composer will not warn).
- [ ] Replace every TBC in the store identity: name, legal name, company number, registered office, VAT number, phone and email (`STORE_*` in `.env`).
