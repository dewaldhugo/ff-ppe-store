<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Contracts;

use App\Domain\VendorSync\Data\MappingResult;
use App\Domain\VendorSync\Data\RawRecord;

/**
 * Turns a vendor's raw record into a normalised product. One
 * implementation per vendor. Pure: no database or network access.
 */
interface VendorMapper
{
    public function map(RawRecord $record): MappingResult;
}
