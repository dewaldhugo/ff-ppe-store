<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Data\NormalisedProduct;
use App\Domain\VendorSync\Models\Vendor;
use Illuminate\Support\Carbon;

/**
 * The vendor's currency is not stable: the same account has returned EUR for
 * different products on different runs. A non-GBP response therefore never
 * replaces a stored GBP price.
 *
 * For every variant the mapper flagged as non-GBP that has a stored price, the
 * writer keeps the stored cost, price, compare price and availability (the
 * variant is "held") and does not advance the product's payload hash, so the
 * next run looks at it again. A variant with no stored price (a first import,
 * a new SKU, a product already unpriced) is not held: it stays unpriced and its
 * product is drafted `non_gbp_price`, as before.
 *
 * It never writes: the writer applies the result, and a dry run only reports it.
 */
final class CurrencyGuard
{
    public function __construct(private readonly StoredPrices $stored) {}

    /**
     * @return array<string, HeldCurrency> By vendor SKU.
     */
    public function evaluate(Vendor $vendor, NormalisedProduct $product, ?int $productId): array
    {
        $flagged = array_values(array_filter($product->variants, fn ($variant): bool => $variant->nonGbpCurrency !== null));

        if ($flagged === []) {
            return [];
        }

        $stored = $this->stored->forSkus($vendor, array_map(fn ($variant): string => $variant->vendorSku, $flagged));
        $held = [];

        foreach ($flagged as $variant) {
            $row = $stored->get($variant->vendorSku);

            if ($row === null || $row->price === null) {
                continue;
            }

            $held[$variant->vendorSku] = new HeldCurrency(
                vendorSku: $variant->vendorSku,
                vendorProductId: $product->vendorProductId,
                productId: $productId,
                productName: $product->name,
                currency: (string) $variant->nonGbpCurrency,
                storedCost: $row->cost_price === null ? null : (int) $row->cost_price,
                storedPrice: (int) $row->price,
                storedComparePrice: $row->compare_price === null ? null : (int) $row->compare_price,
                priceWrittenAt: $this->time($row->price_written_at),
                offerUpdatedAt: $this->time($row->offer_updated_at),
            );
        }

        return $held;
    }

    /**
     * The product's draft reasons once the held variants are taken out:
     * `non_gbp_price` stays only while some non-GBP variant is not held.
     *
     * @param  array<string, HeldCurrency>  $held
     * @return list<string>
     */
    public function reasons(NormalisedProduct $product, array $held): array
    {
        $reasons = $product->draftReasons;

        if ($held === [] || ! in_array(DraftReason::NON_GBP_PRICE, $reasons, true)) {
            return $reasons;
        }

        $open = array_filter(
            $product->variants,
            fn ($variant): bool => $variant->nonGbpCurrency !== null && ! isset($held[$variant->vendorSku]),
        );

        return $open === [] ? array_values(array_diff($reasons, [DraftReason::NON_GBP_PRICE])) : $reasons;
    }

    private function time(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->timezone('Europe/London')->format('Y-m-d H:i:s');
    }
}
