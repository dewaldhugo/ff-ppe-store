<?php

declare(strict_types=1);

namespace App\Livewire\Cart;

use App\Domain\Cart\CartLineViews;
use App\Domain\Cart\CustomerCart;
use App\Domain\Pricing\DisplayPrice;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The cart preview that drops down from the header cart icon (signed-in customers only).
 *
 * The header renders on every page, and pricing a cart is not free, so nothing is loaded
 * until the customer first hovers or focuses the icon (the header sends `mini-cart-open`).
 * After that it refreshes whenever the cart changes (`cart-updated`) and on each hover.
 */
final class MiniCart extends Component
{
    /** Lines shown before "and N more". */
    private const int SHOWN = 4;

    public bool $loaded = false;

    #[On('mini-cart-open')]
    public function load(): void
    {
        $this->loaded = true;
    }

    #[On('cart-updated')]
    public function refresh(): void
    {
        // Rendering again is the refresh.
    }

    public function render(CustomerCart $customerCart, CartLineViews $views): View
    {
        // Price, quantity, line total and the subtotal ex VAT only: VAT and delivery belong to the checkout.
        $cart = $this->loaded ? $customerCart->current() : null;
        $all = $cart === null ? [] : $views->for($cart);

        return view('livewire.cart.mini-cart', [
            'lines' => array_slice($all, 0, self::SHOWN),
            'more' => max(0, count($all) - self::SHOWN),
            'itemCount' => $cart === null ? 0 : (int) $cart->lines->sum('quantity'),
            'subTotal' => $all === [] ? null : (string) $cart->subTotal->formatted(trimTrailingZeros: false),
            'suffix' => DisplayPrice::SUFFIX,
            // Judged on every line, not only the ones shown.
            'canCheckout' => $views->canCheckout($all),
        ]);
    }
}
