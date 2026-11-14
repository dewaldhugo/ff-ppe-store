<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Contracts;

use App\Domain\VendorSync\Data\SourceBatch;
use App\Domain\VendorSync\Exceptions\SourceAborted;

/**
 * Fetches a vendor's raw product records. One implementation per vendor.
 */
interface VendorSource
{
    /**
     * @throws SourceAborted when the feed must not be processed (stale
     *                       file, changed columns, nothing to read).
     */
    public function fetch(): SourceBatch;

    /**
     * Called after a successful run that wrote to the database, for
     * housekeeping such as archiving the processed file.
     */
    public function completed(): void;
}
