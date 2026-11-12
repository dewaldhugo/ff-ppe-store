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
- The storefront reads catalogue data from Lunar models only. It must not read the `vendors` or `vendor_offers` tables, except where a task explicitly says so.

## Data contract

The ingestion module writes data in exactly this shape. The storefront depends on it. Changing it requires updating both sides, so treat it as fixed.

### Channel, currency, tax
- One channel, handle `webstore`.
- One currency, `GBP`, default, 2 decimal places. Prices are integers in pence.
- Prices are stored **exclusive of VAT** (`lunar.pricing.stored_inclusive_of_tax = false`). The same product can carry different VAT for different customers (see VAT below), so a single VAT-inclusive price cannot be stored.
- Tax zone `uk`. Three tax classes:
  - `standard`: 20%.
  - `zero-protective`: protective **boots** (not shoes) and helmets for industrial use. 0% when sold to an individual for their own use, 20% when sold to an employer for use by employees (HMRC VAT Notice 701/23).
  - `zero-childrens`: children's footwear, 0%.
- Listing price (ex VAT) = vendor cost x 1.5, capped at the vendor RRP (ex VAT). Calculated by the ingestion module. The storefront never recalculates it.

### VAT

- The storefront asks at checkout whether the customer is buying for employees. If yes, `zero-protective` lines are charged at 20%; otherwise at 0%. Store the answer on the order (meta `buying_for_employees`).
- Display prices in the customer's mode: inc VAT by default, with an inc/ex VAT toggle remembered per session. Business buyers expect ex VAT prices.
- One customer group, handle `retail`, default.

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
| `safety_class` | Text | safety-footwear | Normalised, no spaces: `SB`, `S1`, `S1P`, `S2`, `S3`, `S3S`, `S7`, `OB`, `O1`, `O2`... |
| `features` | ListField | safety-footwear | Normalised codes: `SRA`, `SRB`, `SRC`, `SR`, `ESD`, `CI`, `HI`, `HRO`, `WR`, `FO`, `M`, `AN`, `E`, `A`, `P`, `METAL_FREE` |
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

All attributes are filterable in search except `name`, `title`, `short_description`, `description`, `key_points`, `country_of_origin`, `video_url`, `certificates`. Waterproofing is the `WR` feature, not a separate attribute.

### Product options
- `colour`: values are customer-facing colour names, title case (`Navy`, `Black`). Each value has meta `hex` (e.g. `394656`).
- `shoe_size`: values are **UK sizes** as strings (`3`, `3.5`, ... `13.5`), with meta `sort` (numeric) for ordering. Variant meta holds `eu_size`.
- `garment_size`: reserved for workwear (`XS` ... `4XL`). Unused at launch.

Every safety-footwear variant has exactly one `colour` value and one `shoe_size` value.

### Variants
- `sku` = `{VENDOR_CODE}-{vendor_sku}`, e.g. `SJ-1492552`, `FS-00052-00095-09`.
- `ean` set when known (may be null).
- `stock` = sellable quantity after the ingestion module applies its safety buffer. `purchasable = 'in_stock'`.
- `shippable = true`. Tax class is one of the three above, set by the ingestion module.
- Dimensions and weight use Lunar's native variant fields when the vendor supplies them (weight in kg; length, width and height of the box in mm). They may be null.
- Variant meta: `eu_size`, `vendor_code`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`. The `vendor_*` values are the vendor's own wording, used to place orders manually.
- One price per variant in GBP for customer group `retail`, ex VAT. `compare_price` = vendor RRP ex VAT when it is higher than the price, otherwise null.

### Products
- `status`: `published` or `draft`. Discontinued products are set to `draft`, never deleted, because orders reference them.
- `brand`: Lunar Brand (e.g. `Safety Jogger`, `Maxguard`, `Amblers Safety`, `GBS`).
- Product meta: `vendor_code`, `vendor_product_id`, `related_vendor_product_ids` (array, for cross-selling), `commodity_code` (UK tariff code, may be null).
- Products the vendor marks as clearance are added to the `clearance` collection in the `ranges` group.
- URLs: Lunar URL records, default language `en`, slug from brand and name.

### Collections
- Collection group `categories`: the customer-facing category tree, e.g. Safety Footwear > Safety Trainers, Safety Boots, Safety Shoes; Occupational Footwear; Slippers. Products belong to one or more leaf collections.
- Collection group `ranges`: marketing collections (New in, Clearance, Industry ranges). Managed in the admin.

### Media
- Spatie Media Library, disk `products`, collection `images`, attached to the product.
- Each image has custom property `colour` (the colour option value it depicts) and `role` (`main`, `side`, `outsole`, `detail`). Variants are linked to their colour's images through Lunar's variant image association, with the `main` image as primary.
- Conversions: `thumb` (300), `medium` (800), `large` (1600), WebP.
- 360-degree spin frames are not imported at launch. A separate table will be added later.

### Orders and fulfilment

Orders are placed with vendors manually at launch (no vendor API ordering). When an order is created, copy onto each order line's meta: `vendor_code`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean`, and `cost_price` (pence, ex VAT, from `vendor_offers` at the time of the order). This snapshot is the record of what was ordered from the vendor and must not change when vendor data changes later. The admin needs a per-vendor view of unfulfilled order lines showing product name, colour, size and quantity, with CSV export.

### Vendor tables (read-only for the storefront)
- `vendors` (id, code, name)
- `vendor_offers` (product_variant_id, vendor_id, vendor_sku, cost_price, stock, last_seen_at, ...). Used only by admin reporting and order routing when a task says so.

## Conventions

- PHP 8.3+, strict types, `final` classes by default, constructor property promotion.
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
