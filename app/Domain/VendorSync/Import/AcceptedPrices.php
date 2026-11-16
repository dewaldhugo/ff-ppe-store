<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

/**
 * Which held price changes a run is told to accept. Always explicit: named
 * SKUs, named vendor product IDs, or everything (`all`, which the command
 * only allows when the operator passes `--all`).
 */
final readonly class AcceptedPrices
{
    /**
     * @param  list<string>  $skus  Vendor SKUs.
     * @param  list<string>  $vendorProductIds  The vendor's product IDs, as printed in brackets in run reports.
     */
    public function __construct(
        public array $skus = [],
        public array $vendorProductIds = [],
        public bool $all = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function everything(): self
    {
        return new self(all: true);
    }

    public function accepts(string $vendorSku, string $vendorProductId): bool
    {
        return $this->all
            || in_array($vendorSku, $this->skus, true)
            || in_array($vendorProductId, $this->vendorProductIds, true);
    }
}
