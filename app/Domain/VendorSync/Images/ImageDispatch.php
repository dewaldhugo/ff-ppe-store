<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

/**
 * What queuing the image jobs of one vendor did: how many jobs went on the
 * queue and how many products were left out because their images are already
 * up to date.
 */
final readonly class ImageDispatch
{
    public function __construct(
        public int $queued,
        public int $skipped = 0,
    ) {}
}
