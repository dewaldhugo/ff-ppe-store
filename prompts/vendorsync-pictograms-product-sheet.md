# Vendorsync pictograms and product sheets (ingestion phase)

**Status:** item 1 (pictograms) is built: the mapper writes `pictograms` from `icons` in the API's order, and `vendorsync:pictograms SJ` downloads the icon files (docs/vendorsync.md, Pictograms). Item 2 (product sheets) is built too: `vendorsync:product-sheets SJ` (docs/vendorsync.md, Product sheets). Only the benefits checklist is not built yet.

This is an ingestion phase, so CLAUDE.md's scope is inverted for it: the ingestion area is in scope and the storefront is not, apart from the touchpoints below. The storefront side is already built and shows nothing until this data exists.

Safety Jogger's `GET /product/{id}` response carries two things the catalogue does not hold yet. The product page shows them like Safety Jogger's own page does.

## Touchpoints outside the ingestion area

- `docs/data-contract.md` (already describes the two additions below), `docs/vendorsync.md`, `CLAUDE.md`.
- `database/seeders/CatalogueFoundationSeeder.php` already defines the `pictograms` attribute. An existing database needs it created once (the seeder is first-deploy only): add it in a migration or by re-running the seeder's attribute step.
- `docs/search.md` applies: the import writes products, so reindex them as the module already does.

## Deliverables

1. **Pictograms.** The response's `icons` list (about 12 entries per footwear product, each `{name, value, url, priority}`, for example `{name: "safetytoecap", value: "Safety toecap", url: ".../static/safetyjogger/icon/safetytoecap.png"}`) goes into the product attribute `pictograms`, a ListField of `name|Label` strings in the order the API lists them (not by the `priority` field, which is not the order the vendor's page shows), for example `safetytoecap|Safety toecap`. Write the list exactly as the vendor gives it: the storefront shows exactly these icons and works nothing out from the product's features, because the vendor chooses the icons per product (an S3 boot lists Puncture resistant and Heel energy absorption with no `P` or `E` feature, and a `CI` feature does not always get a Cold insulated icon). `name` is the icon's file name without `.png` (lower case letters, digits, `_` and `-` only); skip any entry that does not match.

   The icon files are `public/images/pictograms/{name}.png`. The vendor's site draws them in a yellow set at `https://www.safetyjogger.com/static/safetyjogger/icon/industrial/{name}.png` (355 by 361), which is the set the storefront uses (the `url` in the API response is a different, smaller style, so build the address from the name). 14 are already committed: `ce`, `safetytoecap`, `scuffcap`, `punctureresistant`, `slipresistantoutsole`, `heelenergyabsorption`, `oilandfuelresistantoutsole`, `metalfree`, `lightweight`, `waterpenetrationandabsorption`, `electrostaticdischarge35`, `coldinsulated`, `3fitsystem`, `individualorthopedicsolutions`. The set is bigger than that: the vendor's page for BESTBOY METALFREE S3 also shows a Leather icon and a plain ESD icon, which are not committed. Mirror every listed name whose file is not there yet (never overwrite one that is), with the same retry and size limits as image downloads, check each is a real PNG, and report every name that could not be fetched. The storefront leaves out an entry whose file is missing.

   **Benefits checklist.** The vendor's page shows a checklist from the response's `benefits` list (for example "Water resistant", "Customizable footbed", "Orthopaedic fitting (Neskrid)", "ESD-certified footwear"), using each entry's `value`. The product attribute `key_points` currently comes from `keyFeatures` instead, which is a different and thinner list ("S3", "Oil & fuel resistant"). Map `key_points` from `benefits` (the `value` of each, in the API's order, at most 10), and fall back to `keyFeatures` only when `benefits` is empty. Update the Safety Jogger mapper tests to match.
2. **English product sheets.** `RawStore` already keeps the English `PRODUCT_SHEET` documents. Each product has one per colour (for example `LIGERO_NAV_en.pdf`, `LIGERO_BLK_en.pdf`, `LIGERO_ORA_en.pdf`; the file name carries a three letter colour code). Mirror them into the product's Spatie media collection `documents` (disk `products`, constant `ProductMediaDefinitions::DOCUMENTS`), one media item each, with custom properties `type` = `product_sheet`, `language` = `en` and `colour` = the colour option value name it belongs to (the same value the images use, for example `Navy`; resolve the file's colour code to the product's colour, and leave `colour` unset only when the product has a single sheet that covers every colour). Skip a sheet whose colour cannot be matched and report it. Only English product sheets: no other language, no declarations of conformity, no certificates. Mirror a sheet only when the file is new or changed, with the same retry and size limits as image downloads.
3. Both are safe to run again, are part of the change-detection hash so a changed list or file is picked up, and are counted in the run report.
4. Tests for both (fixtures: `tests/Fixtures/VendorSync/SafetyJogger/product-284096.json` has the `icons` and the documents), and updated docs.

## Not in scope

The vendor's "Find this product nearby" and "Request a visit" buttons, certificates, declarations of conformity, other-language product sheets, 360 degree frames, the Academy and campaign content, and the vendor's key feature photos (they are not in the API response; only the `icons` are).

## Acceptance

- `./vendor/bin/pint` and `./vendor/bin/pest` pass.
- After an import, a Safety Jogger product page shows its pictograms and a "Product sheet, {colour} (PDF)" link that changes with the chosen colour.
