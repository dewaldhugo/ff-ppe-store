<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Stores one vendor product payload per (vendor, external id) in
 * `vendor_raw_records`, with the sha256 of its canonicalised form. The
 * image jobs read the image list from here, so no vendor call is needed to
 * know what to download.
 *
 * What is stored is the payload after the source has trimmed it. Trimming
 * happens before storing and before hashing, so memory and row size stay
 * small and a change in trimmed-away data never looks like a product
 * change.
 *
 * Safety Jogger (`SafetyJoggerSource::trim`), from each `GET /product/{id}`:
 *
 * - `taricCodes`: only the United Kingdom (`GB`) entry is kept, reduced to
 *   `country.iso2` and `taricCode`. The other countries (about 230 entries,
 *   a quarter of the response) are dropped. Only the UK commodity code is
 *   imported.
 * - `documents` of type `DeclarationofConformity` and `PRODUCT_SHEET`: only
 *   the English ones are kept (label `English`, a file name ending in
 *   `_EN.pdf`, or a URL in an `/en/` folder). Other languages are dropped.
 *   Documents of every other type (`CERTIFICATE`) are kept in full.
 * - Everything else is stored as fetched, including the 360-degree spin
 *   frame URLs (`colors[].images.360`), so a later spin feature needs no
 *   re-fetch.
 *
 * Additionally left out of the change-detection hash only
 * (`SafetyJoggerSource::hashable`), but still stored:
 *
 * - `colors[].images.360`: stored, not imported yet.
 * - `colors[].skus[].stock` and `stockType`: owned by the stock feed.
 * - `priority`: the catalogue ranking, which is not imported.
 *
 * Footsure (`FootsureSource`): the rows of one ProductID are stored as
 * read. `File_Create_Date` is left out of the change-detection hash, since
 * it changes with every export.
 *
 * A record is written with a single upsert. The previous payload is never
 * loaded.
 */
final class RawStore
{
    public function put(Vendor $vendor, RawRecord $record, ?int $runId): void
    {
        $payload = RawRecord::canonical($record->payload);
        $now = now();

        DB::table('vendor_raw_records')->upsert(
            [[
                'vendor_id' => $vendor->id,
                'external_id' => $record->externalId,
                'payload' => $payload,
                'payload_hash' => hash('sha256', $payload),
                'fetched_at' => $now,
                'last_seen_run_id' => $runId,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['vendor_id', 'external_id'],
            ['payload', 'payload_hash', 'fetched_at', 'last_seen_run_id', 'updated_at'],
        );
    }
}
