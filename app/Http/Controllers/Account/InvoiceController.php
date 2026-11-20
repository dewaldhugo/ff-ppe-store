<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Orders\Invoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * My Account, Invoices: one invoice per placed order, listed and shown as a printable VAT invoice page
 * (the browser's Print / Save as PDF). Scoped to the signed-in customer exactly like the orders.
 */
final class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $orders = OrderController::placedOrders($request)->paginate(OrderController::PER_PAGE)->withQueryString();

        return view('account.invoices.index', ['orders' => $orders]);
    }

    public function show(Request $request, string $reference): View
    {
        $order = OrderController::placedOrders($request)
            ->where('reference', $reference)
            ->with(['lines', 'shippingAddress', 'billingAddress'])
            ->firstOrFail();

        return view('account.invoices.show', ['order' => $order, 'invoice' => new Invoice($order)]);
    }
}
