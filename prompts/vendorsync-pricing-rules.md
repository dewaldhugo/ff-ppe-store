# Vendorsync pricing rules (ingestion phase)

This is an ingestion phase, so CLAUDE.md's scope is inverted for it: the ingestion area is in scope and the storefront is not, apart from the touchpoints below. It changes the pricing rules of the ingestion module after the price audit on branch `feat/pricing-no-rrp-cap`.

## Touchpoints outside the ingestion area

- `docs/data-contract.md` (price rule), `docs/vendorsync.md`, `CLAUDE.md`, `.env.example`, `.env.production.example`.
- `docs/search.md` applies to this change: repricing is a bulk write that affects products, so read it first and make sure the run reindexes every product it writes.

## Deliverables

1. Markup per vendor: `VENDORSYNC_MARKUP` (default 1.5), `VENDORSYNC_SJ_MARKUP`, `VENDORSYNC_FS_MARKUP`; values below 1.0 or above 5 stop the run when the mapper is resolved; each mapper has its own `PriceCalculator`.
2. Remove the cap at the RRP ex VAT. Keep drafting a product when every variant has a cost at or above its RRP ex VAT, now with the reason `cost_at_or_above_rrp`.
3. Non-GBP prices: never converted, never stored. The variant is imported unpriced and unsellable, the product is drafted `non_gbp_price`, and runs count them (blank currency apart from an explicit one, dry runs included).
4. Price-change guard on the vendor cost (default 40%): cost, price and compare price are held together, listed in full in the stored run report, counted in `SyncRunAlert`, and accepted only by explicit SKU or product ID (or a bare flag with `--all`).
5. Repricing of existing data through a pricing fingerprint in the stored product hash.
6. Tests for every rule above, and updated docs.

## Acceptance

- `./vendor/bin/pint` and `./vendor/bin/pest` pass.
- Dry runs for both vendors against the local database report the repricing, the would-be-held list and the non-GBP counts.
