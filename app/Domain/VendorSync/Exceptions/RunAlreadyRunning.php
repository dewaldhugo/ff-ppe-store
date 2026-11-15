<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Exceptions;

use RuntimeException;

/**
 * A run of this vendor and type holds the lock. The second run is refused
 * and leaves no record.
 */
final class RunAlreadyRunning extends RuntimeException
{
    public static function for(string $vendorCode, string $type): self
    {
        return new self("Another {$type} run for {$vendorCode} is already running. This run was not started.");
    }
}
