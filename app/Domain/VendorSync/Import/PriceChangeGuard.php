<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Data\NormalisedProduct;
use App\Domain\VendorSync\Models\Vendor;

/**
 * Holds a variant's vendor cost, price and compare price at their old values
 * when the vendor's new cost differs from the stored `vendor_offers.cost_price`
 * by more than a configured percentage (default 40) in one run.
 *
 * It looks at the cost, never at the price, so a markup change cannot
 * trigger it. It never writes: the writer keeps the old values for the SKUs
 * returned here, and a dry run only reports them.
 *
 * The guard does not apply to creates (no stored cost), to a variant with no
 * stored or no new cost or price, or to a product that is, or was last time,
 * drafted `cost_at_or_above_rrp` or `non_gbp_price`: those are already in
 * data review, and the guard must not stand in the way of fixing them.
 * `no_image` and `vendor_discontinued` do not exempt a product.
 */
final class PriceChangeGuard
{
    private const array EXEMPT_REASONS = [DraftReason::COST_AT_OR_ABOVE_RRP, DraftReason::NON_GBP_PRICE];

    /** Percent of the stored cost. Zero or less switches the guard off. */
    public static function threshold(): float
    {
        return (float) config('vendorsync.price_change_guard_percent', 40);
    }

    public function __construct(private readonly StoredPrices $stored) {}

    /**
     * @param  list<string>  $storedDraftReasons  The product's draft reasons from the previous write.
     * @param  list<string>|null  $currentDraftReasons  The product's draft reasons for this write, after currency holds
     *                                                  (CurrencyGuard::reasons()). Defaults to the mapper's reasons. A held
     *                                                  EUR SKU must not switch the guard off for the product's other SKUs.
     * @return array<string, HeldPrice> By vendor SKU.
     */
    public function evaluate(Vendor $vendor, NormalisedProduct $product, array $storedDraftReasons, ?int $productId, AcceptedPrices $accepted, ?array $currentDraftReasons = null): array
    {
        $threshold = self::threshold();

        if ($threshold <= 0 || array_intersect(self::EXEMPT_REASONS, [...($currentDraftReasons ?? $product->draftReasons), ...$storedDraftReasons]) !== []) {
            return [];
        }

        $skus = array_map(fn ($variant): string => $variant->vendorSku, $product->variants);

        if ($skus === []) {
            return [];
        }

        $stored = $this->stored->forSkus($vendor, $skus);

        $held = [];

        foreach ($product->variants as $variant) {
            $row = $stored->get($variant->vendorSku);

            if ($row === null || $row->cost_price === null || $row->price === null || $variant->costPrice === null || (int) $row->cost_price <= 0) {
                continue;
            }

            $old = (int) $row->cost_price;
            $new = $variant->costPrice;

            // Strictly more than the threshold: exactly 40 percent passes.
            if (abs($new - $old) * 100 <= $old * $threshold) {
                continue;
            }

            if ($accepted->accepts($variant->vendorSku, $product->vendorProductId)) {
                continue;
            }

            $held[$variant->vendorSku] = new HeldPrice(
                vendorSku: $variant->vendorSku,
                vendorProductId: $product->vendorProductId,
                productId: $productId,
                productName: $product->name,
                oldCost: $old,
                newCost: $new,
                oldPrice: (int) $row->price,
                newPrice: $variant->price,
                oldComparePrice: $row->compare_price === null ? null : (int) $row->compare_price,
                newComparePrice: $variant->comparePrice,
                percent: round(($new - $old) * 100 / $old, 1),
            );
        }

        return $held;
    }
}
