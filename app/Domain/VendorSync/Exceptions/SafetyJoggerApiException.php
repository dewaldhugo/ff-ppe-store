<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Exceptions;

use RuntimeException;

/**
 * A Safety Jogger API failure. The message carries the method, the path and
 * the status only: never headers, credentials or a response body.
 */
final class SafetyJoggerApiException extends RuntimeException
{
    public static function status(string $method, string $path, int $status): self
    {
        return new self("Safety Jogger API {$method} /{$path} failed with status {$status}.", $status);
    }

    public static function unreachable(string $method, string $path): self
    {
        return new self("Safety Jogger API {$method} /{$path} could not be reached.");
    }

    public static function notConfigured(): self
    {
        return new self('Safety Jogger API credentials are not configured (VENDORSYNC_SJ_BASE_URL, VENDORSYNC_SJ_USERNAME and VENDORSYNC_SJ_PASSWORD or VENDORSYNC_SJ_TOKEN).');
    }

    public static function malformed(string $method, string $path): self
    {
        return new self("Safety Jogger API {$method} /{$path} returned an unexpected response.");
    }
}
