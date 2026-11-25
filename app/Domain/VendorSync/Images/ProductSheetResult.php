<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

/**
 * What one product's product sheet sync did. Nothing is swallowed: every sheet that could not be matched or saved is listed.
 */
final class ProductSheetResult
{
    /** Sheets downloaded and stored (new, or replaced because the file changed). */
    public int $downloaded = 0;

    /** Sheets already stored and left as they were. */
    public int $kept = 0;

    /** Stored sheets the vendor no longer lists, deleted. */
    public int $removed = 0;

    /** @var list<string> English sheets whose colour could not be matched to one of the product's colours. */
    public array $unmatched = [];

    /** @var list<string> `url: reason` for each sheet that could not be downloaded or saved. */
    public array $failed = [];

    /** Why nothing could be done (no stored payload), or null. */
    public ?string $blocked = null;
}
