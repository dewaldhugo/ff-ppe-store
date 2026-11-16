<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

/**
 * One variant whose latest vendor response was not in GBP while a GBP price is
 * stored. Cost, price, compare price and availability were all kept. Nothing was
 * converted and no price row was deleted.
 */
final readonly class HeldCurrency
{
    public function __construct(
        public string $vendorSku,
        public string $vendorProductId,
        public ?int $productId,
        public string $productName,
        /** The currency the response carried (`(blank)` for a price with no currency). */
        public string $currency,
        public ?int $storedCost,
        public int $storedPrice,
        public ?int $storedComparePrice,
        /** When the stored price row was last written (it moves only when the price changes). */
        public ?string $priceWrittenAt,
        /** The offer row's `updated_at`. It moves on every run, so it is context only. */
        public ?string $offerUpdatedAt,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->vendorSku,
            'vendor_product_id' => $this->vendorProductId,
            'product_id' => $this->productId,
            'product' => $this->productName,
            'currency_seen' => $this->currency,
            'stored_cost' => $this->storedCost,
            'stored_price' => $this->storedPrice,
            'stored_compare_price' => $this->storedComparePrice,
            'stored_price_written_at' => $this->priceWrittenAt,
            'offer_updated_at' => $this->offerUpdatedAt,
        ];
    }

    public function line(string $vendorCode): string
    {
        return sprintf(
            '%s %s [%s] SKU %s: response in %s; kept cost %s, price %s, compare price %s (stored GBP values last written %s); held',
            $vendorCode,
            $this->productName,
            $this->vendorProductId,
            $this->vendorSku,
            $this->currency,
            self::money($this->storedCost),
            self::money($this->storedPrice),
            self::money($this->storedComparePrice),
            $this->priceWrittenAt ?? 'at an unknown time',
        );
    }

    private static function money(?int $pence): string
    {
        return $pence === null ? 'none' : number_format($pence / 100, 2);
    }
}
