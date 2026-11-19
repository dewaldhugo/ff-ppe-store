<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Lunar\Facades\CartSession;
use Lunar\Models\CartLine;

/**
 * The number of items (the sum of line quantities) in the signed-in customer's cart, for
 * the header badge. One query and no pricing, because the header renders on every page.
 *
 * It reads the cart id Lunar keeps in the session: logging in sets it (Lunar's login
 * listener) and logging out clears it, so a customer never sees another's cart here.
 */
final class CartCount
{
    public static function get(): int
    {
        if (! Auth::guard('web')->check()) {
            return 0;
        }

        $cartId = session()->get(CartSession::getSessionKey());

        if (! $cartId) {
            return 0;
        }

        // whereHas('cart') leaves out a deleted cart: its lines stay in the table.
        return (int) CartLine::query()
            ->where('cart_id', $cartId)
            ->whereHas('cart')
            ->sum('quantity');
    }
}
