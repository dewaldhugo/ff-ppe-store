<?php

declare(strict_types=1);

namespace App\View\Components\Storefront;

use App\Domain\Catalogue\Menu\CategoryMenu;
use App\Support\CartCount;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Header extends Component
{
    public function __construct(private readonly CategoryMenu $menu) {}

    public function render(): View
    {
        return view('components.storefront.header', [
            'categories' => $this->menu->tree(),
            'cartCount' => CartCount::get(),
        ]);
    }
}
