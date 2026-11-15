<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Exceptions;

use RuntimeException;

/**
 * The feed must not be processed. The run is recorded as aborted, nothing is
 * written and an alert is sent.
 */
final class SourceAborted extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
