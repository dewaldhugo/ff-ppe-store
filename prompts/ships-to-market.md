# Phase: which products ship where (Safety Jogger UK and EU, Footsure UK only)

Status: Phase 1 is built (see docs/cart.md, Where products ship). Phase 2 is not started and waits for the decisions below. Read docs/data-contract.md, docs/search.md and docs/cart.md first.

## Goal

Safety Jogger (SJ) products can be delivered in the UK and the EU. Footsure (FS) products can be delivered in the UK only. The store delivers to the UK only today (docs/cart.md: the delivery address is UK only, there is no country field, VAT and delivery are UK rules). This phase records where each product can go and uses it. The EU half only goes live after the business decisions below.

## The decision: where "ships to" comes from

**Chosen: work it out from the SKU prefix, with a map in config.** The data contract already fixes every variant SKU as `{VENDOR_CODE}-{vendor_sku}` (`SJ-1492552`, `FS-00052-00095-09`), and a product has one vendor, so the prefix says which vendor a product comes from without reading the vendor tables (which the storefront must not do). `config/store.php` holds the map: `SJ` is `uk` and `eu`, `FS` is `uk`, any other or missing prefix is `uk` only (the safe default).

Why not a product attribute written by the import: it is the more flexible design (one SJ product could be UK only), but it is a data contract change on both sides and needs an ingestion phase, which is a separate piece of work with a separate owner. The stated rule is by vendor, so the prefix map does the job now.

**If per-product exceptions ever appear**, replace the map with a `ships_to` product attribute written by the import. Nothing else changes: the index field and everything that reads it stay the same, only where the value comes from.

Contract assumption, to state in docs/data-contract.md as a clarification (not a change): a variant's SKU prefix is its vendor's code, and every variant of a product has the same prefix.

## Phase 1: the foundation (invisible to customers)

Deliverables:
1. `config/store.php`: `markets` with the vendor map above and the default.
2. `App\Domain\Catalogue\Markets\ShipsTo`: `forSkus(array $skus): list<string>`, `forProduct(Product $product): list<string>`, `euEligible(...)`. A product with mixed prefixes (should not happen) takes the markets they all share. No variants gives `uk` only.
3. The search index: a `ships_to` field on each product document (`StorefrontProductIndexer`), filterable. Bump `IndexSettings::VERSION` to 3. Deploy steps: `php artisan catalogue:sync-search-settings`, then reindex, then `catalogue:verify-index` (docs/search.md: a settings change leaves old documents without the field until they are rewritten). Wrap the reindex in `withoutSyncingToSearch()` as the docs say.
4. A switch `STORE_EU_DELIVERY` (`config/store.php` `eu_delivery`, default false). With it off, nothing about markets is visible anywhere.
5. docs: the SKU clarification in docs/data-contract.md, the `ships_to` field in docs/search.md, the reindex step in docs/launch-checklist.md.

Acceptance criteria:
- SJ SKUs give `uk` and `eu`; FS SKUs give `uk`; an unknown prefix, no SKUs and a blank SKU give `uk`; mixed vendors give the shared markets.
- A reindexed product document carries `ships_to`, and the filter works in Meilisearch.
- With `STORE_EU_DELIVERY` off, no page, price, cart, checkout or email changes.
- `catalogue:verify-index` passes after the reindex.
- Tests for each point, with the fixture seeder, never live vendor data.

## Phase 2: EU delivery (only after the decisions below)

Behind `STORE_EU_DELIVERY`:
1. A delivery country at checkout (UK by default, an allowed list of EU countries). Saved with the address and the order.
2. Enforcement for the chosen country in the cart and at checkout: a product that does not ship there is flagged with a clear message ("not available for delivery outside the UK: remove it or deliver to a UK address"). One order has one destination, so there is no split delivery.
3. Visible labels, only when the switch is on: a "UK delivery only" badge on product pages and cards for UK-only products, and a delivery filter on listings.
4. Delivery charge by destination (`DeliveryPolicy`, `config/store.php` `delivery`) and VAT by destination (a Lunar tax zone for the EU, `StoreTaxDriver`).
5. Orders and invoices carry the destination and, for an export, the wording the accountant gives. Customs details (the commodity code lives in `vendor_products`) need a decision on how they reach the order, since the storefront may not read that table beyond the order line snapshot.
6. The delivery, returns and terms pages, and the order emails.

## Decisions needed before Phase 2 (not assumed anywhere)

- **VAT.** Zero-rated export, with the buyer paying import VAT in their own country? Needs the accountant, and proof of export for each order.
- **Duty and customs.** Who pays duty and import VAT (DAP or DDP), who files the paperwork, and does Safety Jogger ship to the EU itself or through the store? Needs Safety Jogger's EU terms and lead times.
- **Delivery charge to the EU**, and the free-delivery threshold, if any.
- **Customers.** EU businesses only, as in the UK? Is an EU VAT number checked?
- **Currency.** GBP only is assumed. EUR prices are a much bigger change and are not part of this.
- **Returns and warranty** for EU orders.

## Out of scope

Changing the vendor import, per-product exceptions, EUR pricing, and selling Footsure products outside the UK.
