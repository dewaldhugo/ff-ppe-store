<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

/**
 * The outcome of one image download: the bytes, or the reason there are none.
 */
final readonly class DownloadResult
{
    private function __construct(
        public ?string $body,
        public ?string $failure,
        /** The per-host slot lock could not be taken in time, so no request was made. */
        public bool $lockTimedOut,
    ) {}

    public static function ok(string $body): self
    {
        return new self($body, null, false);
    }

    public static function failed(string $reason, bool $lockTimedOut = false): self
    {
        return new self(null, $reason, $lockTimedOut);
    }

    public function succeeded(): bool
    {
        return $this->body !== null;
    }
}
