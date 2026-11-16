<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

/**
 * One variant whose new vendor cost moved by more than the guard allows.
 * Cost, price and compare price were all kept at their old values.
 */
final readonly class HeldPrice
{
    public function __construct(
        public string $vendorSku,
        public string $vendorProductId,
        public ?int $productId,
        public string $productName,
        public int $oldCost,
        public int $newCost,
        public int $oldPrice,
        public ?int $newPrice,
        public ?int $oldComparePrice,
        public ?int $newComparePrice,
        /** Change in cost, in percent, signed, to one decimal place. */
        public float $percent,
    ) {}

    /**
     * @return array<string, int|float|string|null>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->vendorSku,
            'vendor_product_id' => $this->vendorProductId,
            'product_id' => $this->productId,
            'product' => $this->productName,
            'old_cost' => $this->oldCost,
            'new_cost' => $this->newCost,
            'old_price' => $this->oldPrice,
            'new_price' => $this->newPrice,
            'old_compare_price' => $this->oldComparePrice,
            'new_compare_price' => $this->newComparePrice,
            'percent' => $this->percent,
        ];
    }

    public function line(string $vendorCode): string
    {
        return sprintf(
            '%s %s [%s] SKU %s: cost %s to %s (%+.1f%%), price %s to %s; held',
            $vendorCode,
            $this->productName,
            $this->vendorProductId,
            $this->vendorSku,
            self::money($this->oldCost),
            self::money($this->newCost),
            $this->percent,
            self::money($this->oldPrice),
            self::money($this->newPrice),
        );
    }

    private static function money(?int $pence): string
    {
        return $pence === null ? 'none' : number_format($pence / 100, 2);
    }
}
