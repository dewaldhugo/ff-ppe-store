<?php

declare(strict_types=1);

namespace App\View\Components\Home;

use App\Domain\Catalogue\Home\HomeFeaturedProducts;
use App\Domain\Pricing\PriceDisplay;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ProductPicks extends Component
{
    public function __construct(
        private readonly HomeFeaturedProducts $featured,
        private readonly PriceDisplay $priceDisplay,
    ) {}

    public function render(): View
    {
        return view('components.home.product-picks', [
            'products' => $this->featured->pick(),
            'priceDisplay' => $this->priceDisplay,
        ]);
    }
}
