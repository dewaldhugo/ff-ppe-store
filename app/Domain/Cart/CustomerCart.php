<?php

declare(strict_types=1);

namespace App\Domain\Cart;

use Lunar\Exceptions\Carts\CartException;
use Lunar\Facades\CartSession;
use Lunar\Models\Cart;
use Lunar\Models\ProductVariant;

/**
 * The signed-in customer's cart. Lunar owns the cart, its lines, pricing and stock
 * validation (config/lunar/cart.php); this only closes two gaps in Lunar's own actions:
 *
 * - Adding a variant that is already in the cart goes through `updateLine()` with the new
 *   total, because Lunar's add validators only check the quantity being added, so two
 *   adds could together pass the stock.
 * - `updateLine()` changes any cart line by id, whatever cart it belongs to, so a line id
 *   is checked against the customer's own cart first.
 *
 * Guests have no cart: callers check `PriceVisibility` first.
 */
final class CustomerCart
{
    /**
     * The customer's cart with prices calculated, or null when they have none yet.
     */
    public function current(): ?Cart
    {
        return CartSession::current();
    }

    /**
     * Add a quantity of a variant, on top of what is already in the cart.
     *
     * @throws CartRejected
     */
    public function add(ProductVariant $variant, int $quantity): Cart
    {
        // Lunar's availability check is about channel and customer group, not whether the
        // product is still published, so a product withdrawn since the page opened is caught here.
        if (! $variant->isPurchasable()) {
            throw new CartRejected($this->reason($variant, 0, $quantity));
        }

        $cart = CartSession::manager();

        // Lines for the same variant are one line: the storefront adds no line meta.
        $existing = $cart->lines()
            ->where('purchasable_type', $variant->getMorphClass())
            ->where('purchasable_id', $variant->id)
            ->first();

        $inCart = (int) $existing?->quantity;

        try {
            return $existing === null
                ? $cart->add($variant, $quantity)
                : $cart->updateLine($existing->id, $inCart + $quantity);
        } catch (CartException) {
            throw new CartRejected($this->reason($variant, $inCart, $quantity));
        }
    }

    /**
     * Set a line's quantity.
     *
     * @throws CartRejected
     */
    public function setQuantity(int $lineId, int $quantity): Cart
    {
        $cart = $this->ownedBy($lineId);
        $variant = $cart->lines->firstWhere('id', $lineId)->purchasable;

        if (! $variant->isPurchasable()) {
            throw new CartRejected($this->reason($variant, 0, $quantity));
        }

        try {
            return $cart->updateLine($lineId, $quantity);
        } catch (CartException) {
            throw new CartRejected($this->reason($variant, 0, $quantity));
        }
    }

    /**
     * @throws CartRejected
     */
    public function remove(int $lineId): Cart
    {
        return $this->ownedBy($lineId)->remove($lineId);
    }

    /**
     * The current cart, once the line is known to be in it.
     *
     * @throws CartRejected
     */
    private function ownedBy(int $lineId): Cart
    {
        $cart = CartSession::current(calculate: false);

        if ($cart === null || ! $cart->lines->contains('id', $lineId)) {
            throw new CartRejected('That item is no longer in your cart.');
        }

        return $cart;
    }

    private function reason(ProductVariant $variant, int $inCart, int $quantity): string
    {
        $variant->refresh();

        if (! $variant->isPurchasable()) {
            return 'This item is no longer available.';
        }

        if (! $variant->canBeFulfilledAtQuantity($inCart + $quantity)) {
            $left = max(0, $variant->getTotalInventory() - $inCart);

            return match (true) {
                $inCart > 0 && $left === 0 => "You already have all {$inCart} available in your cart.",
                $inCart > 0 => "Only {$left} more available, and you have {$inCart} in your cart.",
                $variant->getTotalInventory() > 0 => "Only {$variant->getTotalInventory()} available.",
                default => 'This item is out of stock.',
            };
        }

        return 'This item cannot be added to your cart right now.';
    }
}
