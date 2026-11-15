<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Data;

final readonly class NormalisedImage
{
    public function __construct(
        public string $url,
        // The colour option value the image depicts.
        public string $colour,
        // main, side, outsole or detail.
        public string $role,
        // A smaller rendition of the same picture, used when the image at
        // `url` is too large to process within the worker's memory.
        public ?string $fallbackUrl = null,
    ) {}
}
