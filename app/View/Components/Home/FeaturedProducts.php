<?php

declare(strict_types=1);

namespace App\View\Components\Home;

use App\Domain\Catalogue\Home\HomeSpotlight;
use App\Domain\Catalogue\Variants\VariantSelection;
use App\Domain\Pricing\PriceDisplay;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The Product Spotlight on the home page (`<x-home.featured-products />`): see HomeSpotlight. The block beside the
 * image is the top of the product page: the brand, name, title, short description, price, stock and the colour and
 * size options, read the same way (VariantSelection), so the two never disagree.
 */
final class FeaturedProducts extends Component
{
    public function __construct(
        private readonly HomeSpotlight $spotlight,
        private readonly PriceDisplay $priceDisplay,
    ) {}

    public function render(): View
    {
        $pick = $this->spotlight->pick();
        $product = $pick['product'] ?? null;

        return view('components.home.featured-products', [
            'pick' => $pick,
            'priceDisplay' => $this->priceDisplay,
            'options' => $product === null ? [] : VariantSelection::forProduct($product)->options(),
            'inStock' => $product !== null && $product->variants->contains(fn ($variant): bool => $variant->stock > 0),
        ]);
    }
}
