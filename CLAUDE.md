# CLAUDE.md

## Project

UK ecommerce store selling personal protective equipment (PPE) to UK customers in GBP. Products are dropshipped from two UK vendors (Safety Jogger, Footsure). Safety footwear is the launch category; workwear, gloves and head protection will follow.

Stack: Laravel (latest version supported by the current Lunar release), Lunar (lunarphp) headless commerce with its Filament admin panel, MySQL 8, Redis, Laravel Horizon, Meilisearch via Laravel Scout, Livewire 3 and Tailwind CSS for the storefront. Hosted on an Ubuntu VPS provisioned with Laravel Forge.

## Local environment (Windows)

- Windows 11, Laravel Herd (free), PHP 8.4 set as Herd's global version, site served at http://ppe-store.test.
- MySQL 8.4 LTS runs natively (same major version as production): databases `ppe_store` and `ppe_store_testing`, user `ppe`.
- Meilisearch 1.56.2 runs natively at http://localhost:7700 with a master key (same version as production).
- No Redis locally. Use `QUEUE_CONNECTION=database`, `CACHE_STORE=database` and `SESSION_DRIVER=database` in `.env`. Production uses Redis and Horizon, so code must work with both drivers: never call Redis directly.
- Horizon cannot run on Windows (no pcntl). Install it, configure it for production, and use `php artisan queue:work` locally.
- Shell commands run in Git Bash on Windows. Avoid Unix-only tooling in scripts and composer hooks.

## Scope boundary (read this first)

This repository contains two areas with different owners:

1. **Storefront and commerce** (your scope): catalogue, product pages, search and filtering, cart, checkout, customer accounts, admin customisation, emails.
2. **Vendor ingestion** (NOT your scope): `app/Domain/VendorSync/**`, `config/vendorsync.php`, `database/migrations/*_vendorsync_*`, and their tests. This module imports vendor data into Lunar and is developed separately.

Rules:
- Never create, edit or delete files inside the ingestion area.
- Never write code that calls vendor APIs or reads vendor feeds.
- Assume the Lunar database is populated correctly according to the data contract below. If the storefront needs data the contract does not provide, stop and report it rather than inventing a workaround.
- The storefront reads catalogue data from Lunar models only. It must not read the `vendors`, `vendor_products` or `vendor_offers` tables. The one permitted read is the order line snapshot at checkout (see Orders and fulfilment). Admin reporting may read them where a task explicitly says so.
- The three `*_vendorsync_*` migrations that create `vendors`, `vendor_products` and `vendor_offers` were created once during Phase 1 so the storefront and fixtures can use the tables. They belong to the ingestion module: never modify them.
- The fixture seeder (local and testing only) is the only storefront code that writes to the vendor tables.

## Ingestion phases

When the current phase prompt is an ingestion phase (prompts/phase-2-vendorsync.md or a later vendorsync prompt), the scope is inverted: the ingestion area (app/Domain/VendorSync, config/vendorsync.php, *_vendorsync_* migrations, tests/*/VendorSync, tests/Fixtures/VendorSync) is in scope and may be created and changed, and the storefront is out of scope except for the touchpoints the phase prompt lists. The three Phase 1 vendorsync migrations remain unchanged; new tables and columns go in new migrations. The data contract still applies in full, and the importer must write exactly the shape the fixture seeder writes.

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

- The storefront asks at checkout whether the customer is buying for employees. If yes, `zero-protective` lines are charged at 20%; otherwise at 0%. Store the answer on the order (meta `buying_for_employees`).
- Display prices in the customer's mode: inc VAT by default, with an inc/ex VAT toggle remembered per session. Business buyers expect ex VAT prices.
- In Lunar the `zero-protective` class carries the individual-use rate (0%). The 20% employer rate is applied by the tax driver at checkout, not by a second tax class.

### Contract handles (key registry)

Lunar has no handle column on tax classes, product types, tax zones or collections, and their names are editable in the admin. The contract identifies these records by handle, so handles are held in an app-owned registry. It belongs to the storefront, not the ingestion area.

- Table `catalogue_keys`: `id`, `key_type` (string), `handle` (string), `model_type` (string), `model_id` (unsigned big int), timestamps. Unique on (`key_type`, `handle`).
- `key_type` values: `tax_class`, `product_type`, `collection`, `tax_zone`. Use it for any other Lunar record the contract identifies by handle where Lunar has no native handle column.
- Records with native handles use them directly: attributes, product options, channels, customer groups, collection groups, currencies (by code), languages (by code).
- `CatalogueFoundationSeeder` creates each Lunar record and its `catalogue_keys` row together, idempotently: look up by key first, create only if missing, never overwrite admin-edited names.
- Service `App\Domain\Catalogue\Keys\CatalogueKeys` resolves a handle to a model, e.g. `CatalogueKeys::taxClass('zero-protective')`, `::productType('safety-footwear')`, `::collection('safety-boots')`, `::taxZone('uk')`. Results are cached and the cache is flushed when the seeder runs. An unknown handle throws a clear exception.
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
| `safety_class` | Text | safety-footwear | Normalised, no spaces: `SB`, `SBP`, `S1`, `S1P`, `S2`, `S3`, `S7`, `OB`, `O1`, `O2`... The EN ISO 20345:2022 classes `S1PS`, `S3S` and `S7S`, and the occupational class `O6`, are valid values. |
| `features` | ListField | safety-footwear | Normalised codes: `SRA`, `SRB`, `SRC`, `SR`, `ESD`, `CI`, `HI`, `HRO`, `WR`, `FO`, `M`, `AN`, `E`, `A`, `P`, `LG` (ladder grip), `SC` (scuff cap), `METAL_FREE` |
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
  - Children's sizes: a variant whose tax class is `zero-childrens` uses the value `Kids {size}` (e.g. `Kids 10`, `Kids 1`), so a child size never shares a value with an adult size. Their `sort` is below every adult size and follows the UK children's scale, where 7 to 13.5 come before 1 to 6.5: `sort = size - 100` for sizes 7 and above, `sort = size - 80` below 7.
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
- Collection group `categories`: the customer-facing category tree, e.g. Safety Footwear > Safety Trainers, Safety Boots, Safety Shoes; Occupational Footwear; Slippers; Plimsolls; Casual Footwear; Accessories. Products belong to one or more leaf collections.
- Contract handles in `categories`: `safety-footwear` (parent of `safety-trainers`, `safety-shoes`, `safety-boots`), `occupational-footwear`, `slippers`, `plimsolls`, `casual-footwear`, `accessories`. In `ranges`: `clearance`.
- Collection group `ranges`: marketing collections (New in, Clearance, Industry ranges). Managed in the admin.
- Collections the contract names (the category tree and `clearance`) are resolved by handle through the key registry. Each also has a Lunar URL whose slug equals its handle.

### Media
- Spatie Media Library, disk `products`, collection `images`, attached to the product.
- Each image has custom property `colour` (the colour option value it depicts) and `role` (`main`, `side`, `outsole`, `detail`). Variants are linked to their colour's images through Lunar's variant image association, with the `main` image as primary.
- Conversions: `thumb` (300), `medium` (800), `large` (1600), WebP.
- 360-degree spin frames are not imported at launch. A separate table will be added later.

### Orders and fulfilment

Orders are placed with vendors manually at launch (no vendor API ordering). When an order is created, the storefront copies from the variant's `vendor_offers` row (and its vendor) onto each order line's meta: `vendor_code`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean`, and `cost_price` (pence, ex VAT, at the time of the order, may be null). This is the one permitted storefront read of the vendor tables. The snapshot is the record of what was ordered from the vendor and must not change when vendor data changes later. The admin needs a per-vendor view of unfulfilled order lines showing product name, colour, size and quantity, with CSV export.

### Vendor tables (owned by the ingestion module)

Vendor identity and ordering data live here, not in Lunar.

- `vendors`: `id`, `code` (unique, e.g. `SJ`, `FS`), `name`.
- `vendor_products`: `product_id` (Lunar product), `vendor_id`, `vendor_product_id`, `commodity_code` (UK tariff code, may be null). Unique on (`vendor_id`, `vendor_product_id`). Non-unique index on `product_id`. For Safety Jogger `vendor_product_id` is the API product `id`; for Footsure it is the `ProductID` column (each `Family Product Reference` is one colourway of that product).
- `vendor_offers`: `product_variant_id` (Lunar variant), `vendor_id`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean` (may be null), `cost_price` (pence ex VAT, may be null), `stock`, `last_seen_at`. Unique on (`vendor_id`, `vendor_sku`). Non-unique index on `product_variant_id`. The `vendor_*` values are the vendor's own wording, used to place orders manually.
- The same Lunar product or variant may be supplied by more than one vendor, so neither table is unique on the Lunar ID. At launch each variant has one offer.
- Cost is never derived from the selling price. It comes only from `vendor_offers.cost_price`.
- The ingestion module adds further tables and columns (raw payloads, RRP, vendor status, change hashes) through new `*_vendorsync_*` migrations. The storefront never depends on them.

### Search index

One Meilisearch document per product, written by Lunar's product indexing through the storefront's custom indexer (`config/lunar/search.php`, `indexers`). Drafts are indexed too because the admin panel searches the same index; the storefront always filters on `status = published`.

- Searchable: `name`, `title`, `brand`, `key_points`, `safety_class`, `standards`.
- Filterable: `collection_ids` (the product's collections and all their ancestors), `brand`, `safety_class`, `features`, `gender`, `standards`, `cut`, `toecap`, `colours_in_stock`, `sizes_in_stock`, `variants_in_stock` (colour and size pairs, e.g. `navy|9`), `in_stock` (bool), `price_min_ex_vat` and `price_min_inc_vat` (pence; inc VAT uses the individual-use rate), `status`.
- Sortable: `price_min_inc_vat`, `price_min_ex_vat`, `created_at`, `sort_order`.
- Index settings are applied by the versioned command `php artisan catalogue:sync-search-settings`, run on every deploy. Never change them by hand.
- Listings and facets query Meilisearch only, through `App\Domain\Catalogue\Search\CatalogueSearch`. No direct Eloquent filtering for listings.
- `SCOUT_QUEUE=true` in production, `false` locally and in tests.
- Bulk writers (the ingestion module, seeders, imports) must wrap their writes in `withoutSyncingToSearch()` and then reindex the affected products in one batch afterwards. A stock or price change on a variant must result in its product being reindexed.

## Conventions

- PHP 8.4, strict types, `final` classes by default, constructor property promotion.
- Domain code under `app/Domain/{Area}`, Livewire components under `app/Livewire/{Area}`.
- Money is never a float. Use Lunar's `Price` and `Money` objects or integers in pence.
- Use Lunar's models, actions, pipelines and events. Do not duplicate cart, pricing, tax or order logic that Lunar already provides. If Lunar cannot do something, explain why before writing custom logic.
- Pest for tests. Every feature ships with feature tests. Use the fixture seeder, never live vendor data.
- Run `./vendor/bin/pint` and `./vendor/bin/pest` before declaring a task done.
- No new composer or npm packages without stating why and asking first.
- UK English in all customer-facing copy (colour, catalogue, favourite). Never use em dashes in copy, comments or docs.

## Workflow

- Work in phases. Each phase prompt lists its deliverables and acceptance criteria. Do not start work outside the current phase.
- Before writing code for a phase, produce a short plan and list any assumption about the data contract.
- Verify package versions and APIs against current documentation (composer, Lunar docs) rather than relying on memory.
- Commit at the end of each completed step with a conventional commit message.
