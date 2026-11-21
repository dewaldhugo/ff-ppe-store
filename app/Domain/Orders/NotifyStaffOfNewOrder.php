<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Mail\OrderPlaced;
use App\Support\Store;
use Illuminate\Support\Facades\Mail;
use Lunar\Models\Order;
use Throwable;

/**
 * Emails the store's staff when an order has just been placed: an account order when the checkout
 * places it, a card order when Stripe reports the payment. The recipients are
 * `STORE_ORDER_NOTIFICATION_EMAILS` (config/store.php), each in an email of their own; with none set
 * nothing is sent. The caller (the Order::saved hook in AppServiceProvider) decides that the order was
 * just placed, and a failure here is reported and never stops the order or the other recipients.
 */
final class NotifyStaffOfNewOrder
{
    public function __invoke(Order $order): void
    {
        // One email for each person, not one email with everyone in the To field: nobody sees the others'
        // addresses, and a failure for one never stops the rest.
        foreach (Store::orderNotificationEmails() as $address) {
            try {
                Mail::to($address)->send(new OrderPlaced($order));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
