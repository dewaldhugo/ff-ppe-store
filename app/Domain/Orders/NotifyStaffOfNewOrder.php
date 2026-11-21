<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Mail\OrderPlaced;
use App\Support\Store;
use Illuminate\Support\Facades\Mail;
use Lunar\Models\Order;

/**
 * Emails the store's staff when an order has just been placed: an account order when the checkout
 * places it, a card order when Stripe reports the payment. The recipients are
 * `STORE_ORDER_NOTIFICATION_EMAILS` (config/store.php); with none set nothing is sent. The caller
 * (the Order::saved hook in AppServiceProvider) decides that the order was just placed, and a failure
 * here is reported and never stops the order.
 */
final class NotifyStaffOfNewOrder
{
    public function __invoke(Order $order): void
    {
        $recipients = Store::orderNotificationEmails();

        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->send(new OrderPlaced($order));
    }
}
