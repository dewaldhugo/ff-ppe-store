<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Data;

/**
 * What a source found: how many products the feed contains, known before
 * any detail is fetched, and the records themselves.
 */
final readonly class SourceBatch
{
    /**
     * @param  iterable<RawRecord>  $records
     */
    public function __construct(
        public int $total,
        public iterable $records,
    ) {}
}
