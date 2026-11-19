<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Lunar\Models\Order;

/**
 * The page a customer lands on after placing an order. An order is only ever shown to the
 * customer who placed it, and only once it is placed; anything else is a 404.
 */
final class OrderConfirmationController extends Controller
{
    public function __invoke(string $reference): View
    {
        $order = Order::query()
            ->where('reference', $reference)
            ->where('user_id', Auth::guard('web')->id())
            ->whereNotNull('placed_at')
            ->with(['lines', 'shippingAddress', 'billingAddress'])
            ->firstOrFail();

        return view('checkout.complete', ['order' => $order]);
    }
}
