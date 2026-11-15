<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use Lunar\Facades\Pricing;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;

/**
 * Shows stored ex VAT prices. The storefront only ever shows them ex VAT.
 *
 * All calculation is Lunar's: the pricing manager picks the price. For
 * zero-protective variants the individual-use rate is 0% until checkout asks
 * whether the order is for employees; the product page says so.
 */
final class PriceDisplay
{
    public function forVariant(ProductVariant $variant): DisplayPrice
    {
        $matched = Pricing::for($variant)->get()->matched;
        $matched->setRelation('priceable', $variant);

        $hasCompare = $matched->compare_price !== null && $matched->compare_price->value > $matched->price->value;

        return new DisplayPrice(
            price: $matched->priceExTax(),
            comparePrice: $hasCompare ? $matched->compare_price : null,
            vatAppliesForEmployers: $variant->tax_class_id === CatalogueKeys::taxClass('zero-protective')->id,
        );
    }

    /**
     * The lowest price among a product's variants.
     */
    public function fromPrice(Product $product): ?DisplayPrice
    {
        $lowest = null;

        foreach ($product->variants as $variant) {
            if ($variant->prices->isEmpty()) {
                continue;
            }

            $price = $this->forVariant($variant);

            if ($lowest === null || $price->price->value < $lowest->price->value) {
                $lowest = $price;
            }
        }

        return $lowest;
    }
}
