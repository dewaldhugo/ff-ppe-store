<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Checkout\CardPayments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lunar\Facades\CartSession;

/**
 * Where Stripe sends the customer after the card form (`/checkout/card-return?payment_intent=...`).
 * It asks Stripe what became of the payment (CardPayments::settle), which places the order when the
 * payment succeeded, and then shows the confirmation, or sends the customer back to the checkout.
 * The query string is never trusted: only the intent id is used, and only for the customer's own cart.
 */
final class CardReturnController extends Controller
{
    public function __invoke(Request $request, CardPayments $card): RedirectResponse
    {
        $intentId = (string) $request->query('payment_intent', '');

        // A Stripe intent id looks like pi_3Abc... and nothing else is accepted.
        abort_unless(preg_match('/^pi_[A-Za-z0-9_]{6,100}$/', $intentId) === 1, 404);

        $cart = $card->cartForIntent($intentId);

        // Another customer's payment, or one this store never started, is not found.
        abort_unless($cart !== null && (int) $cart->user_id === (int) $request->user('web')->getKey(), 404);

        $result = $card->settle($cart, $intentId);

        if ($result->paid && $result->order !== null) {
            // The cart now belongs to the order: the next visit starts a fresh one.
            if ((int) session()->get(CartSession::getSessionKey()) === (int) $cart->id) {
                CartSession::forget(false);
            }

            return redirect()->route('checkout.complete', $result->order->reference);
        }

        if ($result->pending) {
            return redirect()->route('cart')->with('status', 'Your payment is still being confirmed by your bank. Your order is placed as soon as it clears. Please do not pay again.');
        }

        return redirect()->route('checkout')->with('checkout_error', 'Your card payment was not completed, so no order has been placed and you have not been charged. Please try again or use a different card.');
    }
}
