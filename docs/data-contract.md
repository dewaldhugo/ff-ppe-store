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
- Prices are GBP only. A vendor price in any other currency (or with no currency) is never converted. The Safety Jogger API is not stable about currency (the same account has returned EUR for different products on different runs), so a non-GBP response never replaces a GBP price that is already stored: the variant keeps its stored cost, price, compare price and availability, its product is not drafted for it, and the flip is reported (see docs/vendorsync.md, Currency instability). Only a variant with no stored GBP price (a first import, a new SKU) is imported with no price row, no cost and stock 0, and its product is draft (`non_gbp_price`) until a GBP price exists. A product whose every variant has a cost at or above its RRP ex VAT is draft (`cost_at_or_above_rrp`), a data-review flag. A variant can therefore lack a price only on a draft product.
- A vendor cost that moves by more than 40% (configurable) in one run is held: cost, price and compare price keep their old values until a person accepts the change (see docs/vendorsync.md).

## VAT

- **Decision (2026-11-19): every customer is a vetted business buying for its employees**, so `zero-protective` lines are charged at 20%, always. There is no checkout question; the order records `buying_for_employees = true` in its meta. Only `zero-childrens` lines are zero-rated: the cart, the checkout and the confirmation label them "Zero-rated: 0% VAT", and every line shows its VAT rate and amount. The product page still carries the `vatAppliesForEmployers` flag on `DisplayPrice`; it has no visible effect.
- **Delivery** is one flat charge, free once the goods reach the free-from amount: GBP 50 ex VAT, free from GBP 1,000 ex VAT of goods (before discounts, before delivery). Both amounts are in `config/store.php` (`delivery`, pence) and the rule is `App\Domain\Cart\Delivery\DeliveryPolicy`. Delivery is standard rated (20%): an assumption, not confirmed with the accountant.
- The storefront displays, sorts and filters every price ex VAT, with an `ex VAT` suffix (`DisplayPrice::SUFFIX`). There is no inc/ex VAT toggle: the store sells to business buyers. Structured data states each offer ex VAT explicitly (`priceSpecification` with `valueAddedTaxIncluded: false`).
- The compare price is the manufacturer's RRP, ex VAT, and is always labelled as that: never a bare struck-through amount.
- In Lunar the `zero-protective` class keeps the individual-use rate (0%), so the ingestion module's tax class assignment is untouched. The 20% rate is applied by `App\Domain\Pricing\Vat\StoreTaxDriver` (`lunar.taxes.driver` is `store`), which charges a `zero-protective` variant at the `standard` class's rate in the zone, not by a second tax class.

## Contract handles (key registry)

Lunar has no handle column on tax classes, product types, tax zones or collections, and their names are editable in the admin. The contract identifies these records by handle, so handles are held in an app-owned registry. It belongs to the storefront, not the ingestion area.

- Table `catalogue_keys`: `id`, `key_type` (string), `handle` (string), `model_type` (string), `model_id` (unsigned big int), timestamps. Unique on (`key_type`, `handle`); indexed on (`model_type`, `model_id`).
- `key_type` values: `tax_class`, `product_type`, `collection`, `tax_zone`. Use it for any other Lunar record the contract identifies by handle where Lunar has no native handle column.
- Records with native handles use them directly: attributes, product options, channels, customer groups, collection groups, currencies (by code), languages (by code).
- `CatalogueFoundationSeeder` creates each Lunar record and its `catalogue_keys` row together, idempotently: look up by key first, create only if missing, never overwrite admin-edited names.
- Service `App\Domain\Catalogue\Keys\CatalogueKeys` resolves a handle to a model, e.g. `CatalogueKeys::taxClass('zero-protective')`, `::productType('safety-footwear')`, `::collection('safety-boots')`, `::taxZone('uk')`, and the reverse lookup `CatalogueKeys::handleFor(KeyType, Model)`. Results are cached and the cache is flushed when the seeder runs. An unknown handle throws a clear exception.
- The storefront, the fixture seeder and the ingestion module all resolve contract handles through this service only. Never match on name.

## Product types (attribute sets)
- `safety-footwear`: every footwear leaf, safety and non-safety alike (see Collections).
- `workwear`: clothing (jackets, trousers, T-shirts, sweatshirts, activewear, base layers, coveralls).
- `gloves`.
- `head-protection`: eye and face protection, hard hats and helmets, hearing protection, respiratory protection.
- `ppe`: fall protection, overshoes, other PPE.
- `accessories`: socks, headwear, insoles and shoe care, bags, sunglasses, other accessories.

`App\Domain\Catalogue\Collections\CategoryTree::productType()` maps a leaf collection handle to its product type (every footwear leaf to `safety-footwear`; every other leaf by `LEAF_PRODUCT_TYPE`). The ingestion module calls this; it never hard-codes a product type outside the footwear path.

## Product attributes (handles are fixed)

| Handle | Type | On product types | Notes |
|---|---|---|---|
| `name` | TranslatedText | all | Customer-facing product name |
| `title` | TranslatedText | all | Descriptive title, e.g. "Lightweight ESD Safety Shoes" |
| `short_description` | TranslatedText | all | One or two sentences |
| `description` | TranslatedText | all | Long description, plain text with paragraphs |
| `safety_class` | Text | safety-footwear | Normalised, no spaces: `SB`, `SBP`, `S1`, `S1P`, `S2`, `S3`, `S7`, `OB`, `O1`, `O2`... The EN ISO 20345:2022 classes `S1PS`, `S3S` and `S7S`, the occupational class `O6`, and the EN ISO 20346 protective class `PB` are valid values. Null on a non-footwear product. |
| `features` | ListField | safety-footwear | Normalised codes: `SRA`, `SRB`, `SRC`, `SR`, `ESD`, `CI`, `HI`, `HRO`, `WR`, `FO`, `M`, `AN`, `E`, `A`, `P`, `LG` (ladder grip), `SC` (scuff cap), `WRU` (water resistant upper), `METAL_FREE`. Empty on a non-footwear product: neither vendor's feed gives features for clothing, gloves or PPE. |
| `standards` | ListField | all | e.g. `EN ISO 20345:2011`, `EN ISO 20345:2022`, `ASTM F2413:2024`. Empty outside footwear, for the same reason. |
| `toecap` | Text | safety-footwear | e.g. `Nano Carbon`, `Steel`, `Composite`, `None` |
| `midsole` | Text | safety-footwear | |
| `outsole` | Text | safety-footwear | |
| `upper` | Text | safety-footwear | |
| `lining` | Text | safety-footwear | |
| `cut` | Text | safety-footwear | `Low`, `Mid`, `High` |
| `industries` | ListField | all | e.g. `Automotive`, `Logistics`, `Construction` |
| `gender` | Text | all | `Men`, `Women`, `Unisex`. Neither vendor gives a real gender outside footwear in the data imported so far; `Unisex` until one does. |
| `key_points` | ListField | all | Short benefit bullets, 3 to 10 items |
| `fastening` | Text | safety-footwear | e.g. `Laces`, `Slip On`, `Touch Fastening`, `Zip` |
| `country_of_origin` | Text | all | ISO 3166-1 alpha-2, e.g. `RO`, `ES` |
| `video_url` | Text | all | Optional YouTube embed URL |
| `certificates` | ListField | all | Optional, URLs of local copies of conformity certificate PDFs |
| `pictograms` | ListField | all | Optional. The vendor's pictograms (CE, Safety toecap, Puncture resistant...) as `name\|Label` entries in display order, e.g. `safetytoecap\|Safety toecap`. `name` is lower case letters, digits, `_` and `-`; its icon is the file `public/images/pictograms/{name}.png`, and an entry with no file is not shown. Exactly the icons the vendor lists for the product, in its order, shown on the product page under the safety standard. Nothing is worked out from other attributes: the vendor chooses the icons per product (an S3 boot shows Puncture resistant without a `P` feature). 14 icon files are committed in `public/images/pictograms`; a name with no file is not shown. Filled by the ingestion phase in prompts/vendorsync-pictograms-product-sheet.md, so a product shows none until then. |

Waterproofing is the `WR` feature, not a separate attribute. Which attributes are searchable and filterable is defined in search.md.

## Variant attributes

| Handle | Type | On product types | Notes |
|---|---|---|---|
| `eu_size` | Text | safety-footwear | EU size shown as secondary text next to the UK size, e.g. `42`. Set only on a `shoe_size` variant (see Product options): never on a `garment_size` one, because no EU size exists for clothing, gloves or PPE. |

## Product options
- `colour`: values are customer-facing colour names, title case (`Navy`, `Black`). Each value has meta `hex` (e.g. `394656`, may be null).
- `shoe_size`: every footwear product (safety and non-safety alike), including insoles. Values are **UK sizes** as strings (`3`, `3.5`, ... `13.5`), with meta `sort` (numeric) for ordering. The EU size is the variant attribute `eu_size`.
  - Size labels follow the size scale, never the VAT rate. A row whose EU size is below 33 is on the infant scale and its value is `Kids {UK size}` (e.g. `Kids 6`). Every other row uses the plain UK size (`1`, `2`, `6`), whether zero rated or not: junior and adult sizes from UK 1 upward are one physical scale. A zero rated row with no EU size is skipped as a variant and reported.
  - `sort` is the EU size, for both vendors, so the options order correctly (`Kids 6` = 23, `1` = 33, `6` = 39). A size with no EU size sorts by the vendor's chart for that UK size.
- `garment_size`: every non-footwear product (clothing, gloves, head protection, PPE, accessories other than insoles). Normalised by `App\Domain\Catalogue\Options\GarmentSizes`: a known vendor spelling (`Sml`, `Med`, `Lge`, `Xsl`, `XL`...) becomes its short form (`S`, `M`, `L`, `XS`, `XL`...); a size with no real value at all (`Itm`, `OS`, blank) becomes `One Size`; anything else (a waist or chest measurement, a glove size, wording the list does not know) is kept exactly as the vendor gives it. `sort` orders `One Size` first, then the alphabetic scale, then every numeric value ascending, then anything unrecognised, in the order first seen.

Every variant has exactly one `colour` value and exactly one of `shoe_size` or `garment_size`, never both: which one a product uses is fixed for that product (`NormalisedVariant::sizeOptionHandle`), not chosen per variant.

## Variants
- `sku` = `{VENDOR_CODE}-{vendor_sku}`, e.g. `SJ-1492552`, `FS-00052-00095-09`. The storefront relies on the vendor code: every variant of a product has the same one, and `App\Domain\Catalogue\Markets\ShipsTo` reads it to say where the product can be delivered (docs/cart.md, Where products ship). This states what the contract already guaranteed; it changes nothing for the ingestion module.
- `ean` set when known (may be null), in Lunar's native variant column.
- `stock` = sellable quantity after the ingestion module applies its safety buffer. `purchasable = 'in_stock'`.
- `shippable = true`. Tax class is one of the three above, set by the ingestion module.
- Dimensions and weight use Lunar's native variant fields when the vendor supplies them (weight in kg; length, width and height of the box in mm). They may be null.
- Lunar variants have no meta column. Vendor identity and the vendor's own wording live in `vendor_offers` (see vendorsync.md, Vendor tables), not on the variant.
- One price per variant in GBP for customer group `retail`, ex VAT. `compare_price` = vendor RRP ex VAT when it is higher than the price, otherwise null.

## Products
- `status`: `published` or `draft`. Discontinued products are set to `draft`, never deleted, because orders reference them.
- `brand`: Lunar Brand (e.g. `Safety Jogger`, `Maxguard`, `Amblers Safety`, `GBS`). May be null: vendor placeholder brands (Footsure `Miscellaneous Other`) are mapped to no brand by the ingestion module and are never shown to customers.
- Lunar products have no meta column. `vendor_code`, `vendor_product_id` and `commodity_code` live in `vendor_products` (see vendorsync.md, Vendor tables). One Lunar product can have several `vendor_products` rows for a vendor: Footsure lists one product line as several ProductIDs, one per size band, and the line is one product with all its variants (vendorsync.md, Split product lines).
- Related products are Lunar's native product associations of type `cross-sell`. The writer resolves the vendor's related product IDs to Lunar products and creates an association for each one that exists.
- Products the vendor marks as clearance are added to the `clearance` collection in the `ranges` group.
- URLs: Lunar URL records, default language `en`. The product slug is `Str::slug(brand . ' ' . name)`, e.g. `safety-jogger-ligero`. On collision a numeric suffix is appended (`-2`, `-3`, ...) in order of creation. This rule is implemented once, in the action `App\Domain\Catalogue\Urls\GenerateProductSlug`, which the fixture seeder and the ingestion module both call. Lunar's automatic URL generator is disabled. A product's slug is set once when the product is created and is never changed afterwards, including by the ingestion module when a vendor renames a product. Renamed products keep their original URL.

## Collections
- Collection group `categories`: the customer-facing category tree, five top levels and 32 leaves. A product belongs to exactly one leaf collection (never a top level directly, never more than one leaf). The whole tree, in menu order, is the one definition `App\Domain\Catalogue\Collections\CategoryTree::GROUPS`; the foundation seeder creates it and `catalogue:restructure-categories` moves an existing database into it (CLAUDE.md, Launch checklist).
  - **Safety Footwear**: Safety Boots, Safety Shoes, Safety Trainers, Safety Wellingtons, Occupational Footwear, Metal-Free & Metatarsal.
  - **Non-Safety Footwear**: Casual, Formal & Work Shoes; Boots; Sports & Trainers; Hiking & Outdoor; Wellingtons & Waders.
  - **Retired categories**: Slippers (`slippers`), Plimsolls (`plimsolls`) and Sandals & Summer Shoes (`sandals-summer-shoes`) are no longer in the contract (`CategoryTree::RETIRED`). A Footsure Group Class that used to map to one of them is now unmapped, so the ingestion module drafts the product (`unmapped_category`, config/vendorsync.php). `catalogue:restructure-categories` deletes the three collections from an existing database, drafts the products left in no category and reindexes them.
  - **Apparel**: Jackets, Body Warmers & Fleeces; Trousers & Shorts; T-shirts, Polos & Shirts; Sweatshirts & Hoodies; Activewear & Loungewear; Base Layers; Coveralls.
  - **PPE & Gear**: Gloves, Eye & Face Protection, Fall Protection, Overshoes, Hard Hats & Helmets, Hearing Protection, Respiratory Protection, Other PPE.
  - **Accessories**: Socks; Hats, Beanies & Scarves; Insoles, Laces & Shoe Care; Bags & Wallets; Sunglasses; Other Accessories.
  - `CategoryTree::isFootwear($leafHandle)` is true for every leaf under Safety Footwear or Non-Safety Footwear (`FOOTWEAR_TOPS`): these are the only leaves sized on the shoe scale and typed `safety-footwear` (Product types above), with one exception: Insoles (`insoles-laces-shoe-care`, an Accessories leaf) is also shoe-sized, because insole sizes genuinely are shoe sizes; it is still product type `accessories`.
  - A Footsure product's own EN ISO rating can move it between a Safety Footwear and a Non-Safety Footwear leaf even when its vendor Group Class disagrees (`FootsureMapper::footwearLeaf()`): a certified product filed under a non-safety Group Class becomes safety footwear (a certified wellington becomes `safety-wellingtons`, anything else becomes `safety-boots` or `occupational-footwear` by which norm it carries), and an uncertified product filed under a safety Group Class becomes `casual-footwear`. A product whose Group Class already agrees with its rating is untouched. Safety Jogger has no such mismatch: its own category field is trusted as given.
- Collection group `ranges`: marketing collections (New in, Clearance, Industry ranges). Managed in the admin. Contract handle: `clearance`.
- Every leaf collection and `clearance` are resolved by handle through the key registry. Each also has a Lunar URL whose slug equals its handle at creation. The slug may be edited later in the admin; the storefront then records a 301 from the old path (see CLAUDE.md, URLs). Only `categories` collections have a `/shop/...` page.
- **Out of scope, not a collection rule**: a children's, boys' or girls' product (Group Class starting with `Childrens`, `Boys` or `Girls`, or, outside those, a product whose own name says `Junior`, `Jnr`, `Kids` or `Childrens`) is out of scope entirely (`FootsureMapper::isChildrens()`, out-of-scope reason `children`), never given a collection. A small adult size in an ordinary product is not affected: only the Group Class or the product's own name decides this, never its size range.

## Media
- Spatie Media Library, disk `products`, collection `images`, attached to the product.
- Each image has custom property `colour` (the colour option value it depicts) and `role` (`main`, `side`, `outsole`, `detail`). Variants are linked to their colour's images through Lunar's variant image association, with the `main` image as primary.
- Every stored image is WebP, at most 1200 pixels wide and never enlarged (the vendor's original is not kept), with custom property `optimised` naming the format (`webp-1200-q85`). There is no limit on the size of the original that is imported.
- A Safety Jogger main image (role `main`) is also put on a common baseline (on a square canvas, 15% of the height under the subject, at least 15% above it and at least 4% of the width at each side, and a plain background made transparent), so a grid of shoes and boots lines up; its `optimised` is `webp-1200-q85-base15-top15-side4-ar100-grow-bg`. See docs/vendorsync.md, Images.
- Conversions: `thumb` (300), `medium` (800), `large` (1200), WebP.
- Videos: Spatie collection `videos` (disk `products`), no conversions. Not imported: staff upload MP4 or WebM files (up to 200 MB each, the server's PHP and web server upload limits must allow it) in the admin, on the Edit Product screen (Product videos, `ProductVideosResourceExtension`). The product page shows them under the vendor's own YouTube video (`video_url`), in the order set in the admin, or alone when the product has no vendor video. The ingestion module never touches this collection. Staff can also add a video by web address on the same screen (Video links): a YouTube or Vimeo address, or a direct https link to an MP4 or WebM file, checked by `App\Domain\Catalogue\Media\VideoLink` and kept in the storefront-owned table `product_video_links` (`product_id`, `url`, `position`), shown after the uploaded files. An address of any other kind is refused in the admin and never shown. The vendor's `video_url` attribute is separate and unchanged.
- Documents: Spatie collection `documents` (disk `products`), no conversions. Only the English product sheet is mirrored: one PDF per colour, custom properties `type` = `product_sheet`, `language` = `en`, `colour` (the colour option value name, like the images). The product page links the sheet of the selected colour. Certificates, declarations of conformity and other-language sheets are not mirrored or shown. Filled by `php artisan vendorsync:product-sheets SJ` (docs/vendorsync.md, Product sheets), so a product shows no link until it has run.
- 360-degree spin frames are not imported at launch. A separate table will be added later.

## Orders and fulfilment

Built: the snapshot (`App\Domain\Orders\SnapshotVendorOffers`, a step in Lunar's order creation pipeline in `config/lunar/orders.php`, placed after `CleanUpOrderLines`) and the checkout that creates orders (docs/cart.md). Not built: the admin fulfilment view.

Orders are placed with vendors manually at launch (no vendor API ordering). When an order is created, the storefront copies from the variant's `vendor_offers` row (and its vendor) onto each order line's meta: `vendor_code`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean`, and `cost_price` (pence, ex VAT, at the time of the order, may be null). This is the one permitted storefront read of the vendor tables. The snapshot is the record of what was ordered from the vendor and must not change when vendor data changes later. A second step (`App\Domain\Orders\SnapshotLineOptions`) copies the variant's `colour`, `size`, `size_is_uk` and `eu_size` onto the line's meta, so the customer's invoice shows them as they were when the order was placed. The admin needs a per-vendor view of unfulfilled order lines showing product name, colour, size and quantity, with CSV export.
