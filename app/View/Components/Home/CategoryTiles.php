<?php

declare(strict_types=1);

namespace App\View\Components\Home;

use App\Domain\Catalogue\Home\HomeCategoryTiles;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class CategoryTiles extends Component
{
    public function __construct(private readonly HomeCategoryTiles $tiles) {}

    public function render(): View
    {
        return view('components.home.category-tiles', ['tiles' => $this->tiles->tiles()]);
    }
}
