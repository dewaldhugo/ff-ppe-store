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
    ) {}
}
