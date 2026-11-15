<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use Lunar\Facades\Pricing;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;

/**
 * Shows stored ex VAT prices. The storefront always displays them ex VAT;
 * a caller that needs the VAT-inclusive figure asks for it explicitly.
 *
 * All calculation is Lunar's: the pricing manager picks the price and
 * Lunar's Price model adds tax from the variant's tax class in the default
 * tax zone. For zero-protective variants that is the individual-use rate
 * (0%) until checkout asks whether the order is for employees.
 */
final class PriceDisplay
{
    /**
     * The mode the storefront shows prices in: always ex VAT.
     */
    public function mode(): VatDisplayMode
    {
        return VatDisplayMode::default();
    }

    public function forVariant(ProductVariant $variant, ?VatDisplayMode $mode = null): DisplayPrice
    {
        $mode ??= $this->mode();

        $matched = Pricing::for($variant)->get()->matched;
        $matched->setRelation('priceable', $variant);

        $hasCompare = $matched->compare_price !== null && $matched->compare_price->value > $matched->price->value;

        return new DisplayPrice(
            price: $mode === VatDisplayMode::IncVat ? $matched->priceIncTax() : $matched->priceExTax(),
            comparePrice: match (true) {
                ! $hasCompare => null,
                $mode === VatDisplayMode::IncVat => $matched->comparePriceIncTax(),
                default => $matched->compare_price,
            },
            mode: $mode,
            vatAppliesForEmployers: $variant->tax_class_id === CatalogueKeys::taxClass('zero-protective')->id,
        );
    }

    /**
     * The lowest-priced variant's price, for "from" prices on listings.
     * Null when the product has no priced variant.
     */
    public function fromPrice(Product $product, ?VatDisplayMode $mode = null): ?DisplayPrice
    {
        $lowest = null;

        foreach ($product->variants as $variant) {
            if ($variant->prices->isEmpty()) {
                continue;
            }

            $price = $this->forVariant($variant, $mode);

            if ($lowest === null || $price->price->value < $lowest->price->value) {
                $lowest = $price;
            }
        }

        return $lowest;
    }
}
