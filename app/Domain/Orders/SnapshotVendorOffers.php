<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Closure;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Contracts\Order as OrderContract;
use Lunar\Models\ProductVariant;

/**
 * Order creation step (config/lunar/orders.php, after CleanUpOrderLines, which deletes any line whose
 * meta differs from its cart line's, so the snapshot must come after it): copies the vendor's
 * own wording for each variant onto the order line's meta, as docs/data-contract.md
 * (Orders and fulfilment) requires, so the order records what to order from the vendor and
 * never changes when vendor data does. This is the one place the storefront reads the vendor
 * tables. A variant with no vendor offer (a hand-made one) simply gets no snapshot.
 */
final class SnapshotVendorOffers
{
    public function handle(OrderContract $order, Closure $next): mixed
    {
        $lines = $order->lines()
            ->where('type', 'physical')
            ->where('purchasable_type', (new ProductVariant)->getMorphClass())
            ->get();

        foreach ($lines as $line) {
            $offer = DB::table('vendor_offers')
                ->join('vendors', 'vendors.id', '=', 'vendor_offers.vendor_id')
                ->where('vendor_offers.product_variant_id', $line->purchasable_id)
                ->orderBy('vendor_offers.id')
                ->first([
                    'vendors.code as vendor_code',
                    'vendor_offers.vendor_sku',
                    'vendor_offers.vendor_product_name',
                    'vendor_offers.vendor_colour',
                    'vendor_offers.vendor_size',
                    'vendor_offers.ean',
                    'vendor_offers.cost_price',
                ]);

            if ($offer === null) {
                continue;
            }

            $line->update(['meta' => array_merge((array) $line->meta, [
                'vendor_code' => $offer->vendor_code,
                'vendor_sku' => $offer->vendor_sku,
                'vendor_product_name' => $offer->vendor_product_name,
                'vendor_colour' => $offer->vendor_colour,
                'vendor_size' => $offer->vendor_size,
                'ean' => $offer->ean,
                'cost_price' => $offer->cost_price,
            ])]);
        }

        return $next($order);
    }
}
