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
    public function __construct(private readonly PriceVisibility $visibility) {}

    /**
     * Whether the current visitor may see prices (signed-in customers only).
     * Callers that render a price must check this or use fromPrice().
     */
    public function visible(): bool
    {
        return $this->visibility->visible();
    }

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
     * The lowest price among a product's variants, or null when the visitor
     * may not see prices or no variant has one.
     */
    public function fromPrice(Product $product): ?DisplayPrice
    {
        if (! $this->visible()) {
            return null;
        }

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
