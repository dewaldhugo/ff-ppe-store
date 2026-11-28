<?php

declare(strict_types=1);

namespace App\View\Components\Home;

use App\Domain\Catalogue\Home\HomeSpotlight;
use App\Domain\Catalogue\Variants\VariantSelection;
use App\Domain\Pricing\PriceDisplay;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The Product Spotlight on the home page (`<x-home.featured-products />`): see HomeSpotlight. Every campaign is on the page, one at random showing first, and arrows (the ones of the video section) slide the others in. The block beside the
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
        // Every campaign that can be shown, each with the options and stock of its product, and a random one to start on: the arrows
        // page through the rest.
        $campaigns = array_map(fn (array $campaign): array => [
            ...$campaign,
            'options' => VariantSelection::forProduct($campaign['product'])->options(),
            'inStock' => $campaign['product']->variants->contains(fn ($variant): bool => $variant->stock > 0),
        ], $this->spotlight->available());

        return view('components.home.featured-products', [
            'campaigns' => $campaigns,
            'start' => $campaigns === [] ? 0 : random_int(0, count($campaigns) - 1),
            'priceDisplay' => $this->priceDisplay,
        ]);
    }
}
