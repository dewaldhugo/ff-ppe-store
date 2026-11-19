<?php

declare(strict_types=1);

namespace App\Livewire\Cart;

use App\Domain\Cart\CartLineViews;
use App\Domain\Cart\CartRejected;
use App\Domain\Cart\CustomerCart;
use App\Domain\Pricing\DisplayPrice;
use App\Support\CartCount;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Lunar\Models\Cart;
use Lunar\Models\CartLine;

/**
 * The cart page (/cart), for signed-in customers (route middleware `auth:web`).
 *
 * Lunar owns the cart and its prices; every change goes through CustomerCart, which
 * runs Lunar's stock validation and checks the line belongs to this customer.
 */
final class CartPage extends Component
{
    /**
     * Each line's quantity as typed, by cart line id. Strings, so a cleared field
     * or a stray character never breaks the page.
     *
     * @var array<int, string>
     */
    public array $quantities = [];

    /**
     * What is wrong with a line, by cart line id.
     *
     * @var array<int, string>
     */
    public array $lineErrors = [];

    /** Said once after a line is removed. */
    public ?string $notice = null;

    /** A refusal that is not about a line on the page, such as one already removed in another tab. */
    public ?string $error = null;

    public function mount(): void
    {
        $this->fillQuantities($this->cart()->current());
    }

    public function updatedQuantities(string $value, string $key): void
    {
        $lineId = (int) $key;

        $this->notice = null;
        $this->error = null;
        unset($this->lineErrors[$lineId]);

        $quantity = $this->parseQuantity($value);

        if ($quantity === null) {
            $this->lineErrors[$lineId] = 'Enter a whole number, 1 or more.';

            return;
        }

        try {
            $this->cart()->setQuantity($lineId, $quantity);
        } catch (CartRejected $rejected) {
            $this->reject($lineId, $rejected);
        }

        // Show what the cart really holds, whether or not the change went through.
        $this->fillQuantities($this->cart()->current());
        $this->announce();
    }

    public function remove(int $lineId): void
    {
        $this->notice = null;
        $this->error = null;
        unset($this->lineErrors[$lineId], $this->quantities[$lineId]);

        try {
            $this->cart()->remove($lineId);
            $this->notice = 'The item was removed from your cart.';
        } catch (CartRejected $rejected) {
            $this->reject($lineId, $rejected);
        }

        $this->announce();
    }

    public function render(CartLineViews $views): View
    {
        // Price, quantity, line total and the subtotal ex VAT only. VAT and delivery are worked out
        // and shown at the checkout, never here (docs/cart.md).
        $cart = $this->cart()->current();
        $lines = $cart === null ? [] : $views->for($cart);

        return view('livewire.cart.cart-page', [
            'lines' => $lines,
            'itemCount' => $cart === null ? 0 : (int) $cart->lines->sum('quantity'),
            'subTotal' => $lines === [] ? null : (string) $cart->subTotal->formatted(trimTrailingZeros: false),
            'suffix' => DisplayPrice::SUFFIX,
            // Checkout opens only when every line can be bought as it stands.
            'canCheckout' => $views->canCheckout($lines),
        ])->layout('components.layouts.storefront', [
            'title' => 'Cart',
            'noindex' => true,
        ]);
    }

    private function cart(): CustomerCart
    {
        return app(CustomerCart::class);
    }

    /**
     * Put the refusal under its line when that line is on the page, otherwise at the top.
     */
    private function reject(int $lineId, CartRejected $rejected): void
    {
        if ($this->cart()->current()?->lines->contains('id', $lineId)) {
            $this->lineErrors[$lineId] = $rejected->getMessage();

            return;
        }

        $this->error = $rejected->getMessage();
    }

    /**
     * Tell the header badge the new count.
     */
    private function announce(): void
    {
        $this->dispatch('cart-updated', count: CartCount::get());
    }

    private function fillQuantities(?Cart $cart): void
    {
        $this->quantities = $cart === null
            ? []
            : $cart->lines->mapWithKeys(fn (CartLine $line): array => [$line->id => (string) $line->quantity])->all();
    }

    /**
     * The quantity as a whole number of at least 1, or null for anything else.
     */
    private function parseQuantity(string $value): ?int
    {
        $value = trim($value);

        if (! ctype_digit($value) || strlen($value) > 6) {
            return null;
        }

        return (int) $value >= 1 ? (int) $value : null;
    }
}
