<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Orders\Reorder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Lunar\Models\Order;

/**
 * My Account, Orders: the signed-in customer's placed orders and one page per order. An order is only
 * ever shown to the customer who placed it, and only once it is placed; anything else is a 404. Nothing
 * here takes a user id from the request.
 */
final class OrderController extends Controller
{
    public const int PER_PAGE = 10;

    public function index(Request $request): View
    {
        return view('account.orders.index', ['orders' => self::placedOrders($request)->paginate(self::PER_PAGE)->withQueryString()]);
    }

    public function show(Request $request, string $reference): View
    {
        $order = self::placedOrders($request)
            ->where('reference', $reference)
            ->with(['lines', 'shippingAddress', 'billingAddress'])
            ->firstOrFail();

        return view('account.orders.show', ['order' => $order]);
    }

    /**
     * "Buy again": the goods from this order go into the customer's cart (App\Domain\Orders\Reorder), then on to
     * the cart. When nothing can be added the customer stays on the order with the reasons.
     */
    public function reorder(Request $request, Reorder $reorder, string $reference): RedirectResponse
    {
        $order = self::placedOrders($request)->where('reference', $reference)->with('lines.purchasable')->firstOrFail();

        $result = $reorder($order);
        $items = $result['added'].' '.Str::plural('item', $result['added']);
        $notAdded = $result['skipped'] === [] ? '' : ' Not added: '.implode(', ', $result['skipped']).'.';

        if ($result['added'] === 0) {
            return redirect()->route('account.orders.show', $order->reference)
                ->with('status', 'Nothing from order '.$order->reference.' could be added to your cart.'.$notAdded);
        }

        return redirect()->route('cart')->with('status', 'Added '.$items.' from order '.$order->reference.' to your cart. Prices are today\'s.'.$notAdded);
    }

    /**
     * The customer's placed orders, newest first.
     *
     * @return Builder<Order>
     */
    public static function placedOrders(Request $request): Builder
    {
        /** @var User $user */
        $user = $request->user('web');

        return Order::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('placed_at')
            ->latest('placed_at')
            ->latest('id');
    }
}
