<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Exceptions;

use RuntimeException;

/**
 * An image job that could do no work at all: the product is unknown, there
 * is nothing to download, or every download failed. Thrown so the job lands
 * in `failed_jobs` instead of finishing silently.
 */
final class ImageSyncFailed extends RuntimeException
{
    public static function for(string $vendorProductId, string $reason): self
    {
        return new self("Image sync failed for vendor product {$vendorProductId}: {$reason}");
    }
}
