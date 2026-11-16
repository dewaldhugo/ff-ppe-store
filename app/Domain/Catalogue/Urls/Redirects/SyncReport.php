<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls\Redirects;

final readonly class SyncReport
{
    /**
     * @param  array<string, string>  $redirects  old path => new path, written (or that would be, on a dry run)
     * @param  list<array{string, string}>  $refused  old path, new path
     */
    public function __construct(
        public array $redirects = [],
        public array $refused = [],
        public int $snapshotsCreated = 0,
        public int $snapshotsRemoved = 0,
    ) {}
}
