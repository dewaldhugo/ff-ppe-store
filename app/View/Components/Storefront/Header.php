<?php

declare(strict_types=1);

namespace App\View\Components\Storefront;

use App\Domain\Catalogue\Menu\CategoryMenu;
use App\Support\CartCount;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

final class Header extends Component
{
    public function __construct(private readonly CategoryMenu $menu) {}

    public function render(): View
    {
        // Customers only. A staff member signed in to Lunar is on the `staff` guard and is a guest here.
        $customer = Auth::guard('web')->user();
        $company = $customer?->latestCustomer()?->company_name;

        return view('components.storefront.header', [
            'categories' => $this->menu->tree(),
            'cartCount' => CartCount::get(),
            'customer' => $customer,
            // Shown after the customer's name; a user with no Lunar customer or no company shows the name alone.
            'companyName' => filled($company) ? (string) $company : null,
        ]);
    }
}
