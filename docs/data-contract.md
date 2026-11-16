# Data contract

The ingestion module writes data in exactly this shape. The storefront depends on it. Changing it requires updating both sides, so treat it as fixed.

## Channel, currency, tax
- One channel, handle `webstore`.
- One currency, `GBP`, default, 2 decimal places. Prices are integers in pence.
- One customer group, handle `retail`, default.
- Prices are stored **exclusive of VAT** (`lunar.pricing.stored_inclusive_of_tax = false`). The same product can carry different VAT for different customers (see VAT below), so a single VAT-inclusive price cannot be stored.
- Tax zone `uk` (default zone). Three tax classes, identified by handle through the key registry (see Contract handles), because Lunar tax classes have no handle column:
  - `standard`: 20%.
  - `zero-protective`: protective **boots** (not shoes) and helmets for industrial use. 0% when sold to an individual for their own use, 20% when sold to an employer for use by employees (HMRC VAT Notice 701/23).
  - `zero-childrens`: children's footwear, 0%.
- Listing price (ex VAT) = vendor cost x the vendor's markup (default 1.5, overridable per vendor), rounded to the penny. It is not capped at the RRP, so a variant can sell above the manufacturer's RRP; it then has no compare price. Calculated by the ingestion module. The storefront never recalculates it.
- Prices are GBP only. A vendor price in any other currency (or with no currency) is never converted: the variant is imported with no price row, no cost and stock 0, and its product is draft (`non_gbp_price`) until a GBP price exists. A product whose every variant has a cost at or above its RRP ex VAT is draft (`cost_at_or_above_rrp`), a data-review flag. A variant can therefore lack a price only on a draft product.
- A vendor cost that moves by more than 40% (configurable) in one run is held: cost, price and compare price keep their old values until a person accepts the change (see docs/vendorsync.md).

## VAT

- Specified, not yet built (checkout is a placeholder page): the storefront asks at checkout whether the customer is buying for employees. If yes, `zero-protective` lines are charged at 20%; otherwise at 0%. Store the answer on the order (meta `buying_for_employees`). Until then the product page only flags that VAT may apply for employers.
- The storefront displays, sorts and filters every price ex VAT, with an `ex VAT` suffix (`DisplayPrice::SUFFIX`). There is no inc/ex VAT toggle: the store sells to business buyers. Structured data states each offer ex VAT explicitly (`priceSpecification` with `valueAddedTaxIncluded: false`).
- The compare price is the manufacturer's RRP, ex VAT, and is always labelled as that: never a bare struck-through amount.
- In Lunar the `zero-protective` class carries the individual-use rate (0%). The 20% employer rate is to be applied by the tax driver at checkout (not yet built), not by a second tax class.

## Contract handles (key registry)

Lunar has no handle column on tax classes, product types, tax zones or collections, and their names are editable in the admin. The contract identifies these records by handle, so handles are held in an app-owned registry. It belongs to the storefront, not the ingestion area.

- Table `catalogue_keys`: `id`, `key_type` (string), `handle` (string), `model_type` (string), `model_id` (unsigned big int), timestamps. Unique on (`key_type`, `handle`); indexed on (`model_type`, `model_id`).
- `key_type` values: `tax_class`, `product_type`, `collection`, `tax_zone`. Use it for any other Lunar record the contract identifies by handle where Lunar has no native handle column.
- Records with native handles use them directly: attributes, product options, channels, customer groups, collection groups, currencies (by code), languages (by code).
- `CatalogueFoundationSeeder` creates each Lunar record and its `catalogue_keys` row together, idempotently: look up by key first, create only if missing, never overwrite admin-edited names.
- Service `App\Domain\Catalogue\Keys\CatalogueKeys` resolves a handle to a model, e.g. `CatalogueKeys::taxClass('zero-protective')`, `::productType('safety-footwear')`, `::collection('safety-boots')`, `::taxZone('uk')`, and the reverse lookup `CatalogueKeys::handleFor(KeyType, Model)`. Results are cached and the cache is flushed when the seeder runs. An unknown handle throws a clear exception.
- The storefront, the fixture seeder and the ingestion module all resolve contract handles through this service only. Never match on name.

## Product types (attribute sets)
- `safety-footwear` (launch category)
- `workwear`, `gloves`, `head-protection` (created now, unused until later)

## Product attributes (handles are fixed)

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

Waterproofing is the `WR` feature, not a separate attribute. Which attributes are searchable and filterable is defined in search.md.

## Variant attributes

| Handle | Type | On product types | Notes |
|---|---|---|---|
| `eu_size` | Text | safety-footwear | EU size shown as secondary text next to the UK size, e.g. `42` |

## Product options
- `colour`: values are customer-facing colour names, title case (`Navy`, `Black`). Each value has meta `hex` (e.g. `394656`, may be null).
- `shoe_size`: values are **UK sizes** as strings (`3`, `3.5`, ... `13.5`), with meta `sort` (numeric) for ordering. The EU size is the variant attribute `eu_size`.
  - Size labels follow the size scale, never the VAT rate. A row whose EU size is below 33 is on the infant scale and its value is `Kids {UK size}` (e.g. `Kids 6`). Every other row uses the plain UK size (`1`, `2`, `6`), whether zero rated or not: junior and adult sizes from UK 1 upward are one physical scale. A zero rated row with no EU size is skipped as a variant and reported.
  - `sort` is the EU size, for both vendors, so the options order correctly (`Kids 6` = 23, `1` = 33, `6` = 39). A size with no EU size sorts by the vendor's chart for that UK size.
- `garment_size`: reserved for workwear (`XS` ... `4XL`). Unused at launch.

Every safety-footwear variant has exactly one `colour` value and one `shoe_size` value.

## Variants
- `sku` = `{VENDOR_CODE}-{vendor_sku}`, e.g. `SJ-1492552`, `FS-00052-00095-09`.
- `ean` set when known (may be null), in Lunar's native variant column.
- `stock` = sellable quantity after the ingestion module applies its safety buffer. `purchasable = 'in_stock'`.
- `shippable = true`. Tax class is one of the three above, set by the ingestion module.
- Dimensions and weight use Lunar's native variant fields when the vendor supplies them (weight in kg; length, width and height of the box in mm). They may be null.
- Lunar variants have no meta column. Vendor identity and the vendor's own wording live in `vendor_offers` (see vendorsync.md, Vendor tables), not on the variant.
- One price per variant in GBP for customer group `retail`, ex VAT. `compare_price` = vendor RRP ex VAT when it is higher than the price, otherwise null.

## Products
- `status`: `published` or `draft`. Discontinued products are set to `draft`, never deleted, because orders reference them.
- `brand`: Lunar Brand (e.g. `Safety Jogger`, `Maxguard`, `Amblers Safety`, `GBS`). May be null: vendor placeholder brands (Footsure `Miscellaneous Other`) are mapped to no brand by the ingestion module and are never shown to customers.
- Lunar products have no meta column. `vendor_code`, `vendor_product_id` and `commodity_code` live in `vendor_products` (see vendorsync.md, Vendor tables).
- Related products are Lunar's native product associations of type `cross-sell`. The writer resolves the vendor's related product IDs to Lunar products and creates an association for each one that exists.
- Products the vendor marks as clearance are added to the `clearance` collection in the `ranges` group.
- URLs: Lunar URL records, default language `en`. The product slug is `Str::slug(brand . ' ' . name)`, e.g. `safety-jogger-ligero`. On collision a numeric suffix is appended (`-2`, `-3`, ...) in order of creation. This rule is implemented once, in the action `App\Domain\Catalogue\Urls\GenerateProductSlug`, which the fixture seeder and the ingestion module both call. Lunar's automatic URL generator is disabled. A product's slug is set once when the product is created and is never changed afterwards, including by the ingestion module when a vendor renames a product. Renamed products keep their original URL.

## Collections
- Collection group `categories`: the customer-facing category tree, e.g. Safety Footwear > Safety Trainers, Safety Boots, Safety Shoes; Occupational Footwear; Slippers; Plimsolls; Casual Footwear; Accessories; Wellingtons. Products belong to one or more leaf collections.
- Contract handles in `categories`: `safety-footwear` (parent of `safety-trainers`, `safety-shoes`, `safety-boots`), `occupational-footwear`, `slippers`, `plimsolls`, `casual-footwear`, `accessories`, `wellingtons`. In `ranges`: `clearance`.
- Collection group `ranges`: marketing collections (New in, Clearance, Industry ranges). Managed in the admin.
- Collections the contract names (the category tree and `clearance`) are resolved by handle through the key registry. Each also has a Lunar URL whose slug equals its handle at creation. The slug may be edited later in the admin; the storefront then records a 301 from the old path (see CLAUDE.md, URLs). Only `categories` collections have a `/shop/...` page.

## Media
- Spatie Media Library, disk `products`, collection `images`, attached to the product.
- Each image has custom property `colour` (the colour option value it depicts) and `role` (`main`, `side`, `outsole`, `detail`). Variants are linked to their colour's images through Lunar's variant image association, with the `main` image as primary.
- Conversions: `thumb` (300), `medium` (800), `large` (1600), WebP.
- 360-degree spin frames are not imported at launch. A separate table will be added later.

## Orders and fulfilment

Specified, not yet built: there is no order snapshot code and no admin fulfilment view in the repository yet (checkout is a placeholder page).

Orders are placed with vendors manually at launch (no vendor API ordering). When an order is created, the storefront copies from the variant's `vendor_offers` row (and its vendor) onto each order line's meta: `vendor_code`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean`, and `cost_price` (pence, ex VAT, at the time of the order, may be null). This is the one permitted storefront read of the vendor tables. The snapshot is the record of what was ordered from the vendor and must not change when vendor data changes later. The admin needs a per-vendor view of unfulfilled order lines showing product name, colour, size and quantity, with CSV export.
