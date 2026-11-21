<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Illuminate\Support\Facades\DB;
use Lunar\Models\Cart;
use Lunar\Models\Order;

/**
 * Deletes an order for good, from the admin (docs/cart.md, Deleting an order). Lunar has no delete for an
 * order and its lines, addresses and transactions all point at it, so they are removed with it, in one
 * transaction: either the whole order goes or none of it.
 *
 * - The cart that made the order is kept, with its link to the order cleared.
 * - The order's invoice number is kept in `invoice_numbers` with no order, so the sequence has a gap that is
 *   on record and the number is never given to another order (migration keep_invoice_numbers_of_deleted_orders).
 * - The search index entry goes with the model (Scout).
 * - A card payment taken for the order is NOT refunded: that is done in Stripe. `warning()` says so.
 */
final class DeleteOrder
{
    public function __invoke(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            Cart::query()->where('order_id', $order->id)->update(['order_id' => null]);

            $order->transactions()->delete();
            $order->lines()->delete();
            $order->addresses()->delete();
            $order->tags()->detach();
            $order->activities()->delete();

            $order->delete();
        });
    }

    /** True when a card payment was taken for the order: deleting it does not give the money back. */
    public function paymentTaken(Order $order): bool
    {
        return $order->captures()->where('success', true)->exists();
    }

    /** What the confirmation says before anything is deleted. */
    public function warning(Order $order): string
    {
        $text = 'This permanently deletes order '.$order->reference.', its lines, addresses and payment records. It cannot be undone.';

        if ($order->isPlaced()) {
            $text .= ' It has been placed, so its invoice number is kept on record and will not be used again.';
        }

        if ($this->paymentTaken($order)) {
            $text .= ' A card payment was taken for it. Deleting the order does not refund the card: refund it in Stripe first if the customer is owed the money.';
        }

        return $text;
    }
}
