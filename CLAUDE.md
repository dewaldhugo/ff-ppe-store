# CLAUDE.md

## Project

UK ecommerce store for Frontline Safety Gear (frontlinesafetygear.com), selling personal protective equipment (PPE) to UK customers in GBP. This repository started as a copy of the ppe-store project and is now independent of it: its own repository, databases, Redis and search prefixes, and server site. Products are dropshipped from two UK vendors (Safety Jogger, Footsure). Safety footwear is the launch category; workwear, gloves and head protection will follow.

Stack: PHP 8.4, Laravel 13, Lunar 1.5 (headless commerce, with its Filament 4 admin panel), MySQL 8, Redis, Laravel Horizon, Meilisearch via Laravel Scout, Livewire 3 and Tailwind CSS 4 (Vite 7) for the storefront, Spatie Media Library for images, Pest and Pint for tests and style. Hosted on a manually configured Debian 13 VPS (no Forge): this repository does not automate deployment.

## Local environment (Windows)

- Windows 11, Laravel Herd (free), PHP 8.4 set as Herd's global version, site served at https://ff-ppe-store.test (Herd names the site after the folder).
- MySQL 8.4 LTS runs natively (same major version as production): databases `ff_ppe_store` and `ff_ppe_store_testing`, user `ppe` (granted on both). Never point this project at the `ppe_store` databases or the unprefixed search index: they belong to the other site.
- Meilisearch 1.56.2 runs natively at http://localhost:7700 with a master key (same version as production).
- No Redis locally. Use `QUEUE_CONNECTION=database`, `CACHE_STORE=database` and `SESSION_DRIVER=database` in `.env`. Production uses Redis and Horizon, so code must work with both drivers: never call Redis directly.
- Horizon cannot run on Windows (no pcntl). Install it, configure it for production, and use `php artisan queue:work --queue=default,images,conversions` locally (`composer dev` does). Queue names are in `App\Support\Queues` (the one definition; configs and jobs use the constants); each has its own Horizon supervisor in production. A batch ignores its jobs' own `onQueue()`, so name the queue on the batch (`ImageRun` does); a test fails when a queueable class does not set its queue from `Queues`. `composer.json` fakes `ext-pcntl` and `ext-posix` under `config.platform` so Horizon installs on Windows: do not remove it.
- MySQL, Meilisearch, Redis and the server OS are operator-maintained: the repository cannot verify their versions.
- Shell commands run in Git Bash on Windows. Avoid Unix-only tooling in scripts and composer hooks.

## Scope boundary (read this first)

This repository contains two areas with different owners:

1. **Storefront and commerce** (your scope): catalogue, product pages, search and filtering, cart, checkout, customer accounts, admin customisation, emails.
2. **Vendor ingestion** (NOT your scope): `app/Domain/VendorSync/**`, `config/vendorsync.php`, `database/migrations/*_vendorsync_*`, and their tests (`tests/Feature/VendorSync`, `tests/Unit/VendorSync`, `tests/Fixtures/VendorSync`, `tests/Support/VendorSync.php`). The module is wired in by one line in `bootstrap/providers.php` (`VendorSyncServiceProvider`) and reads `database/fixtures/catalogue.json` through the fixture seeder. This module imports vendor data into Lunar and is developed separately.

Rules:
- Never create, edit or delete files inside the ingestion area.
- Never write code that calls vendor APIs or reads vendor feeds.
- Assume the Lunar database is populated correctly according to the data contract (docs/data-contract.md). If the storefront needs data the contract does not provide, stop and report it rather than inventing a workaround.
- The storefront reads catalogue data from Lunar models only. It must not read the `vendors`, `vendor_products` or `vendor_offers` tables. The one permitted read is the order line snapshot at checkout (see docs/data-contract.md, Orders and fulfilment). Admin reporting may read them where a task explicitly says so.
- The three Phase 1 `*_vendorsync_*` migrations (`vendors`, `vendor_products`, `vendor_offers`) were created so the storefront and fixtures can use the tables. They belong to the ingestion module: never modify them. Later `*_vendorsync_*` migrations also belong to the ingestion module.
- The fixture seeder (local and testing only) is the only storefront code that writes to the vendor tables. `DatabaseSeeder` may also call the ingestion importer locally when `VENDORSYNC_SEED_FROM_FIXTURES=true`.

## Ingestion phases

When the current phase prompt is an ingestion phase (prompts/phase-2-vendorsync.md or a later vendorsync prompt), the scope is inverted: the ingestion area (app/Domain/VendorSync, config/vendorsync.php, *_vendorsync_* migrations, tests/*/VendorSync, tests/Fixtures/VendorSync, tests/Support/VendorSync.php) is in scope and may be created and changed, and the storefront is out of scope except for the touchpoints the phase prompt lists (for Phase 2: `database/fixtures/catalogue.json`, `DatabaseSeeder`, the `bootstrap/providers.php` line). Existing vendorsync migrations remain unchanged; new tables and columns go in new migrations. The data contract still applies in full, and the importer must write exactly the shape the fixture seeder writes.

Ingestion rules for Safety Jogger and Footsure, the run safety limits, image handling, commands and the vendor table definitions are in [docs/vendorsync.md](docs/vendorsync.md). The current pricing-rules phase is [prompts/vendorsync-pricing-rules.md](prompts/vendorsync-pricing-rules.md); `docs/search.md` applies to it, because repricing is a bulk write that affects products.

Pricing rules (summary, details in docs/vendorsync.md): price = vendor cost x markup (default 1.5, per-vendor override, 1.0 to 5), never capped at the RRP; vendor prices that are not GBP are never converted or stored, and the Safety Jogger currency is unstable (EUR for different products on different runs), so a product is re-fetched up to 2 more times (after 2 s, then 10 s) and a non-GBP response never replaces a stored GBP price: the stored cost, price, compare price and availability are kept, the product is not drafted for it, the flip is reported (`held_currency`) and the product's hash is not advanced so the next run retries (a variant with no stored GBP price is left unpriced and its product drafted `non_gbp_price`; see "Currency instability" in docs/vendorsync.md); a product whose every variant has cost at or above its RRP is drafted `cost_at_or_above_rrp`; a vendor cost change above 40% is held for review (`--accept-price-changes=SKU,..`, `--accept-product=ID`, or bare with `--all`). Known gap: a product published by hand in the admin while a variant has no price breaks the storefront product page (Lunar `MissingCurrencyPriceException`); the importer keeps such products draft.

## Data contract

The data contract in [docs/data-contract.md](docs/data-contract.md) is binding. The ingestion module writes data in exactly that shape and the storefront depends on it. Changing it requires updating both sides, so treat it as fixed. Highlights that apply everywhere:

- Prices are integers in pence, stored ex VAT and shown ex VAT with an `ex VAT` suffix. There is no inc/ex VAT toggle (business buyers). A variant of a draft product may have no price row (non-GBP vendor price); a published product never does.
- Contract records without a native Lunar handle (tax classes, product types, tax zones, collections) are resolved by handle through `App\Domain\Catalogue\Keys\CatalogueKeys`. Never match on name.
- Product slugs come from `App\Domain\Catalogue\Urls\GenerateProductSlug` and never change once set.
- Listings and facets query Meilisearch only, through `App\Domain\Catalogue\Search\CatalogueSearch`.
- Built: the order line vendor snapshot, the checkout and card payments through Stripe (docs/cart.md). Not built: the per-vendor fulfilment view in the admin, order emails. There is no `buying_for_employees` question: every customer is treated as buying for employees (data contract, VAT).

## Docs

Read the matching file before you start the work it covers.

- Read [docs/data-contract.md](docs/data-contract.md) before touching seeders, search, catalogue, pricing, tax, media or order code.
- Read [docs/vendorsync.md](docs/vendorsync.md) before any ingestion phase work, or before reading or writing the vendor tables.
- Read [docs/search.md](docs/search.md) before changing the search indexer, index settings, listings or facets, or any bulk write that affects products.
- Read [docs/accounts.md](docs/accounts.md) before touching registration, login, password reset, email verification, My Account, the `login`/`register`/`account` routes, the customer and staff guards, or the mail those send.
- Read [docs/cart.md](docs/cart.md) before touching the cart, add to cart, the header cart badge, VAT on lines, delivery, the checkout, orders or `config/lunar/{cart*,orders,payments,taxes}.php`.
- Read [docs/launch-checklist.md](docs/launch-checklist.md) before any deploy or go-live task.

## Conventions

- PHP `^8.4` and constructor property promotion. Strict types and `final` classes are enforced by Pint (`pint.json`); `config/` and abstract classes are exempt.
- Domain code under `app/Domain/{Area}`, Livewire components under `app/Livewire/{Area}`. Small cross-cutting helpers live in `app/Support`; Blade components under `resources/views/components/{ui,home,storefront,layouts}`.
- Money is never a float. Use Lunar's `Price` and `Money` objects or integers in pence.
- Use Lunar's models, actions, pipelines and events. Do not duplicate cart, pricing, tax or order logic that Lunar already provides. If Lunar cannot do something, explain why before writing custom logic.
- Pest for tests, in `tests/Feature/{Accounts,Cart,Catalogue,Deployment,Pricing,Storefront,VendorSync}` and `tests/Unit`. Every feature ships with feature tests. Use the fixture seeder, never live vendor data.
- Run `./vendor/bin/pint` and `./vendor/bin/pest` before declaring a task done.
- No new composer or npm packages without stating why and asking first.
- UK English in all customer-facing copy (colour, catalogue, favourite). Never use em dashes in copy, comments or docs.

## URLs

Current routes (`routes/web.php`):

| Route name | URI | Target |
|---|---|---|
| `home` | `/` | view `home` |
| `shop` | `/shop` | view `pages.shop` (every top-level category) |
| (none) | `/products` | 301 to `/shop` |
| `category` | `/shop/{path}` | `CategoryListing`. `{path}` is the URL slugs of the collection's ancestors plus its own, e.g. `/shop/safety-footwear/safety-boots` |
| `search` | `/search` | `CategoryListing` |
| `brands` | `/brands` | `BrandIndexController`: brand tiles (logo or monogram) from `BrandDirectory`; customers select one or more |
| `brands.show` | `/brands/{brands}` | `CategoryListing` limited to the chosen brands. `{brands}` is one slug or several joined by commas; `ResolveBrandSlugs` 301s to the sorted, lower-case, de-duplicated form and 404s an unknown slug |
| `product` | `/products/{slug}` | `ProductPage` |
| `about` | `/about` | view `pages.about` (real content, supplied by the client) |
| `contact` | `/contact` | view `pages.contact`: enquiry form (Livewire `EnquiryForm`) emailing the store email (`config/store.php`), or `STORE_ENQUIRY_EMAIL` when set |
| `login`, `register` | `/login`, `/register` | `LoginController`, `RegisterController` (`guest:web`, noindex; docs/accounts.md) |
| `password.request`, `password.email`, `password.reset`, `password.store` | `/forgot-password`, `/reset-password/{token}` | `PasswordResetLinkController`, `NewPasswordController` (`guest:web`) |
| `logout` | POST `/logout` | `LoginController@destroy` (regenerates the session, never invalidates it) |
| `verification.notice`, `verification.verify`, `verification.send` | `/email/verify`, `/email/verify/{id}/{hash}`, POST `/email/verification-notification` | `EmailVerificationController` (`auth:web`) |
| `account`, `account.profile.update`, `account.password.update` | `/account`, PUT `/account/profile`, PUT `/account/password` | `AccountController` (`auth:web`, `account.verified`, noindex) |
| `favourites`, `favourites.toggle` | `/favourites`, POST `/favourites/{product}` | `FavouriteController` (`auth:web`, noindex; docs/accounts.md, Favourites) |
| `account.orders`, `account.orders.show`, `account.invoices`, `account.invoices.show` | `/account/orders`, `/account/orders/{reference}`, `/account/invoices`, `/account/invoices/{reference}` | `Account\OrderController`, `Account\InvoiceController` (`auth:web`, `account.verified`, noindex; docs/accounts.md, Member area) |
| `account.orders.reorder`, `account.addresses`, `account.addresses.*` | POST `/account/orders/{reference}/reorder`, `/account/addresses` (create, edit, default, delete) | `Account\OrderController@reorder`, `Account\AddressController` (`auth:web`, `account.verified`; docs/accounts.md, Member area) |
| `cart` | `/cart` | `CartPage` (`auth:web`, noindex; docs/cart.md) |
| `checkout`, `checkout.complete` | `/checkout`, `/checkout/complete/{reference}` | `CheckoutPage` (`auth:web`, noindex, own clean layout), `OrderConfirmationController` (the customer's own placed order only; docs/cart.md) |
| placeholders | `/terms`, `/privacy`, `/cookies`, `/returns`, `/delivery`, `/accessibility` | `pages.placeholder` (noindex) |

Rules:

- Always link with `route()`; never hard-code paths in views or tests. Product links use `route('product', $slug)`. **Category links go through `App\Domain\Catalogue\Urls\CategoryUrl`** (`for($collection, $params)` or `forHandle($handle, $params)`), which builds the path from the collection's ancestry. `CollectionPath` is the only code that works a path out, and `CollectionPath::PREFIX` (`shop`) is the only definition of the prefix.
- Only collections in the `categories` group have a `/shop` URL. `ranges` collections (Clearance, Industry ranges) 404 there. They will get their own prefix (for example `/ranges/{slug}`) in a later task.
- Collection URL slugs equal their contract handles at creation. After that the slug is editable in the admin, and the path follows the slug and the collection's position in the tree.
- `ResolveCategoryPath` (route middleware) resolves the path before the page mounts: an exact match renders; a retired path, a wrong ancestry or a case-only difference gets a 301 to the correct path; anything else is a 404. Two parents may each have a child with the same slug, so the full path is matched, never the last segment alone. Paths are compared in PHP, never through the database collation. `ResolveProductSlug` does the same case check for products.
- Trailing slashes 301 to the version without (`RedirectTrailingSlash`, storefront GET and HEAD routes only).
- Canonical links: a category listing with any query string other than `page` points at the unfiltered category URL; `?page=N` points at itself. `/search` keeps its own query in its canonical.
- When a category's slug or position changes, the old path is stored in `url_redirects` (`old_path`, `new_path`, `created_at`, unique on `old_path`) and 301s to the new one, for the category and every descendant. Chains collapse (A to B to C resolves A to C) and loops are refused. Detection runs from the Collection and Url model events (`CollectionRedirectObserver`), and `php artisan catalogue:sync-redirects [--dry-run]` is the backstop for raw tree rebuilds. It is scheduled hourly (`routes/console.php`, independent of `VENDORSYNC_SCHEDULE_ENABLED`) and runs after migrations at deploy (docs/launch-checklist.md). `collection_path_snapshots` holds each category's last known path.

## Storefront

- **Brands.** `storefront_brands` (slug, name, logo, tile colour) and `storefront_brand_members` (which Lunar brands it covers) are storefront-owned; the Lunar brands table is never changed. `StorefrontBrandSeeder` holds the brand list and logos (`public/images/brands`); run it after an import that adds brands. A Lunar brand with no storefront record still appears, with a slug from its name and a monogram. Product counts and brand pages query Meilisearch through `CatalogueSearch` (`ListingQuery::brandScope`).
- **Design tokens.** Colours are tokens in the `@theme` block of `resources/css/app.css` (`brand-blue`, `brand-teal`, `brand-navy`, `ink`, `ink-muted`, `surface-muted`, `white`, `border`, `danger`, `success` and the disabled pair). Templates use only these tokens. `tests/Feature/Storefront/DesignTokensTest.php` reads the CSS and requires WCAG AA contrast (4.5:1) for every text and background pair used.
- **Buttons.** `x-ui.button` has two variants: primary (`brand-blue` fill, white text) and `accent` (`brand-teal` fill, `brand-navy` text), which is only for navy surfaces. `x-ui.button-outline` is the secondary button (`on-dark` for navy surfaces).
- **Noindex.** One flag, `STORE_NOINDEX` (`config/store.php`, default true), puts `<meta name="robots" content="noindex">` on every page. Placeholder pages also carry their own noindex. See docs/launch-checklist.md. Noindex works through the meta tag only.
- **robots.txt.** `public/robots.txt` exists and disallows `/lunar`, `/horizon`, `/cart`, `/checkout` and `/account`. It is a static file: `STORE_NOINDEX` does not change it, and it does not keep the store out of search engines (a crawler must be able to fetch a page to see its noindex tag, so never Disallow the catalogue there).
- **Config-driven home page.** `config/homepage.php` holds the "Shop by standard" chips (`standards`) and the trust strip claims (`trust`; an empty slot is not rendered).
- **Store identity.** `config/store.php` and `App\Support\Store` hold it. The address, phone number and email are fixed in `config/store.php` (no env variable). The other `STORE_*` values come from `.env` and each reads `TBC` until it is known (`STORE_NOINDEX` and `STORE_REQUIRE_EMAIL_VERIFICATION` are switches, not identity).
- **Customer accounts.** Full detail in [docs/accounts.md](docs/accounts.md). Hand-rolled on Laravel's own auth (no Fortify, Breeze or Jetstream). A customer is an `App\Models\User` on the `web` guard with a Lunar `Customer` attached through `customer_user`; staff are `Lunar\Admin\Models\Staff` on Lunar's separate `staff` guard and are never customers. Every storefront auth route names its guard (`auth:web`, `guest:web`). Customer logout regenerates the session and must never invalidate it, or a staff session in the same browser ends. `RegisterCustomer` and `UpdateCustomerProfile` write the User and Customer in one transaction and index the customer after the commit (`CustomerSearch`), so a search outage never undoes a sign-up. Account emails (`CustomerVerifyEmail`, `CustomerResetPassword`) go on the `default` queue. `STORE_REQUIRE_EMAIL_VERIFICATION` (default true) gates My Account on a verified address; `app:preflight` fails when it is on and `MAIL_MAILER` is `log` or `array`. Prices are shown to signed-in customers only (`App\Domain\Pricing\PriceVisibility`; guests see no price, no price filter or price sorts, no quantity field or add to cart button, and no `offers` in the JSON-LD; details in docs/accounts.md). New registrations must be activated by staff in the admin panel before they can sign in; then they see prices. New customers can pay by card only (Stripe, docs/cart.md); staff switch on 30 day account terms per customer (docs/accounts.md).

## Workflow

- Work in phases. Each phase prompt lists its deliverables and acceptance criteria. Do not start work outside the current phase.
- Before writing code for a phase, produce a short plan and list any assumption about the data contract.
- Verify package versions and APIs against current documentation (composer, Lunar docs) rather than relying on memory.
- Commit at the end of each completed step with a conventional commit message.

## Later

Known follow-ups, not in the current phase:

- Merge vendor products split by size band into one Lunar product with several `vendor_products` rows. Footsure lists one line as several ProductIDs (Morris, Dunlop Sport, 6045/PSG27 plimsolls, Audrey Slipper, Primera Gardening Shoe); each is its own product today, with numeric slug suffixes.

## Launch checklist

Full list, with details, in [docs/launch-checklist.md](docs/launch-checklist.md). Nothing in it is done by default. Before go-live:

- [ ] Lift noindex (`STORE_NOINDEX=false` in production, and the placeholder pages' own noindex).
- [ ] Verify every homepage "Shop by standard" chip returns products on real data.
- [ ] Confirm the trust strip claims with the client.
- [ ] Confirm `pcntl` and `posix` exist in the server's PHP CLI before starting Horizon.
- [ ] Replace every TBC in the store identity (`STORE_*`).
- [ ] Before the first deploy of the customer accounts release, set `STORE_REQUIRE_EMAIL_VERIFICATION=false` in `shared/.env` (or configure a real `MAIL_MAILER` and `MAIL_FROM_ADDRESS`). Otherwise `app:preflight` fails by design. Password reset emails also need a real mailer (docs/accounts.md).
- [ ] Run `php artisan app:preflight` first on every deploy, and `php artisan catalogue:verify-media` and `php artisan catalogue:verify-index` after any import.
- [ ] Run `CatalogueFoundationSeeder` on the first deploy only, and use `storage:link --force`.
- [ ] Run `php artisan catalogue:sync-redirects` after migrations on every deploy (first run only takes snapshots), and make sure the server cron runs `schedule:run`.
