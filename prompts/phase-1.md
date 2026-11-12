# Phase 1: Foundation, catalogue and product pages

Read `CLAUDE.md` in full before starting. The data contract in it is binding.

## Goal

A running Laravel and Lunar application with the catalogue structure from the data contract, realistic fixture data, a customer-facing category listing with faceted filtering, and a product detail page with colour and size selection. No cart or checkout yet.

## Inputs

- `database/fixtures/catalogue.json`: eight products in contract shape, derived from real vendor data (two Safety Jogger, six Footsure). Treat its structure as the reference example of what the ingestion module will produce. Do not change it.

## Steps

Produce a plan first, listing every assumption you make about the data contract. Wait for my approval, then work through the steps in order. Commit after each step.

1. **Project setup.** Install Laravel and Lunar (core and admin panel) following the current Lunar documentation. Configure MySQL, Redis queue and cache, Horizon, and Pint. Set `lunar.pricing.stored_inclusive_of_tax` to false (prices are stored ex VAT). Add a `products` filesystem disk (local, public visibility) for media.
2. **Catalogue foundation seeder** (`CatalogueFoundationSeeder`). Idempotent: running it twice changes nothing. Creates the channel, currency, language, tax zone, the three tax classes with their rates, customer group, the four product types, all attributes with their handles and product type assignments, the `colour`, `shoe_size` and `garment_size` options, and the `categories` collection group with this tree:
   - Safety Footwear (`safety-footwear`) > Safety Trainers (`safety-trainers`), Safety Shoes (`safety-shoes`), Safety Boots (`safety-boots`)
   - Occupational Footwear (`occupational-footwear`)
   - Slippers (`slippers`)
   It also creates the `ranges` collection group with a `clearance` collection.
   The ingestion module will rely on this seeder in production, so it must be safe to run on every deploy.
3. **Fixture seeder** (`FixtureCatalogueSeeder`, local and testing environments only). Reads `catalogue.json` and creates brands, products, variants, option values, prices, URLs and collection membership exactly as the contract specifies. Generate simple placeholder images locally (one per colour, using the colour hex where available) instead of downloading anything. Never seed fixtures in production.
4. **Search.** Configure Scout with Meilisearch. Index products with: name, title, brand, collection slugs, every filterable attribute from the contract, available colours, available UK sizes (only sizes with stock above zero), minimum price, and an `in_stock` flag. Configure filterable and sortable attributes in Meilisearch through a versioned command, not by hand. Verify the current recommended way to extend Lunar's product indexing before implementing it.
5. **Storefront layout.** Livewire 3 and Tailwind. Header with category navigation built from the `categories` collection group, search box, footer. Mobile-first and accessible (semantic HTML, keyboard navigable, visible focus states). Plain, professional styling for now; design comes later.
6. **Category listing page** (`/c/{slug}`). Products in the collection and its descendants. Facets: brand, safety class, features, UK size, colour, gender, price range, in stock only. Facet counts come from Meilisearch. Filter state lives in the query string so filtered URLs can be shared and indexed. Sorting: relevance, price ascending, price descending, newest. Pagination of 24.
7. **Product detail page** (`/p/{slug}`). Gallery that switches to the selected colour's images. Colour swatches and UK size selector (EU size shown as secondary text). Sizes without stock are shown but disabled. Price, compare price when present, stock message ("In stock", "Only 3 left", "Out of stock"). Specification table built from attributes (safety class, features with plain-English labels, standards, toecap, midsole, outsole, upper, lining, cut). Description and short description. Optional video embed. Related products from `related_vendor_product_ids` where those products exist and are published. Product structured data (JSON-LD `Product` with `offers` per variant) and correct canonical URL.
8. **Price display.** Prices are stored ex VAT. Show inc VAT by default with an inc/ex VAT toggle in the header, remembered in the session. For `zero-protective` variants, assume the individual-use rate (0%) until checkout and show a short note that VAT applies when buying for employees. Use Lunar's pricing and tax system for the calculation; do not hand-roll VAT maths.
9. **Feature labels.** A single source for mapping feature codes to customer labels and tooltips (for example `SRC` to "Slip resistant (SRC)", `ESD` to "ESD, electrostatic dissipative", `METAL_FREE` to "Metal free"), used by both facets and the product page.

## Acceptance criteria

- `php artisan migrate:fresh --seed` on a clean database produces a browsable store with all eight fixture products.
- Running `CatalogueFoundationSeeder` a second time produces no changes.
- Filtering Safety Footwear by `S1P` and `SRC` returns Ligero only.
- Selecting Black on Ligero shows only black images and the sizes available in black.
- Out-of-stock products (SX310 in the fixtures) appear in listings with an out-of-stock badge and are excluded when "in stock only" is selected.
- The FS9 product page shows the same price with the toggle on inc or ex VAT (0% rate); the Ligero page shows a 20% difference.
- Pest feature tests cover: foundation seeder idempotency, inc/ex VAT display per tax class, listing filters and counts, product page variant selection, JSON-LD output. All tests and Pint pass.
- No file inside the ingestion area exists or was touched.

## Out of scope for this phase

Cart, checkout, payments, customer accounts, CMS pages, emails, final visual design, anything inside the ingestion area.
