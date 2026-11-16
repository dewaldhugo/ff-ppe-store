<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lunar\Models\ProductVariant;

/**
 * What the database holds for a vendor's SKUs right now: the stored cost, the
 * stored price and compare price (quantity 1), and when each was written.
 * Read only. Shared by the price-change guard and the currency guard.
 */
final class StoredPrices
{
    /**
     * `price_written_at` is the price row's own `updated_at`. The writer only
     * touches that row when the price or compare price changes, so it says when
     * the stored GBP values were last written. The offer's `updated_at` moves on
     * every run (`last_seen_at`), so it is returned too but cannot say that.
     *
     * @param  list<string>  $vendorSkus
     * @return Collection<string, object{vendor_sku: string, cost_price: ?int, price: ?int, compare_price: ?int, price_written_at: ?string, offer_updated_at: ?string}>
     */
    public function forSkus(Vendor $vendor, array $vendorSkus): Collection
    {
        if ($vendorSkus === []) {
            return collect();
        }

        return DB::table('vendor_offers as o')
            ->leftJoin('lunar_prices as p', function ($join): void {
                $join->on('p.priceable_id', '=', 'o.product_variant_id')
                    ->where('p.priceable_type', (new ProductVariant)->getMorphClass())
                    ->where('p.min_quantity', 1);
            })
            ->where('o.vendor_id', $vendor->id)
            ->whereIn('o.vendor_sku', $vendorSkus)
            ->get([
                'o.vendor_sku',
                'o.cost_price',
                'o.updated_at as offer_updated_at',
                'p.price',
                'p.compare_price',
                'p.updated_at as price_written_at',
            ])
            ->keyBy('vendor_sku');
    }
}
