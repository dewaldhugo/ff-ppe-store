<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use App\Domain\VendorSync\Contracts\StockFeed;

/**
 * `GET /stock` in one call. Rows are identified by `skuId`, never by EAN:
 * the real feed has duplicate EANs and SKUs without one.
 */
final readonly class SafetyJoggerStockFeed implements StockFeed
{
    public function __construct(private ApiClient $client) {}

    public function stockBySku(): array
    {
        $stock = [];

        foreach ($this->client->stock() as $row) {
            $skuId = (string) ($row['skuId'] ?? '');

            if ($skuId !== '') {
                $stock[$skuId] = max(0, (int) ($row['stock'] ?? 0));
            }
        }

        return $stock;
    }
}
