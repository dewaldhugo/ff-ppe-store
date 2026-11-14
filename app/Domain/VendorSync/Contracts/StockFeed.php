<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Contracts;

/**
 * A vendor's fast stock feed, separate from its catalogue.
 */
interface StockFeed
{
    /**
     * The vendor's stock figure by vendor SKU (vendor_offers.vendor_sku).
     *
     * @return array<string, int>
     */
    public function stockBySku(): array;
}
