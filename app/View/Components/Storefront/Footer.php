<?php

declare(strict_types=1);

namespace App\View\Components\Storefront;

use App\Domain\Catalogue\Menu\CategoryMenu;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Footer extends Component
{
    public function __construct(private readonly CategoryMenu $menu) {}

    public function render(): View
    {
        return view('components.storefront.footer', ['categories' => $this->menu->tree()]);
    }
}
