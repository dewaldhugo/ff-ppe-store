<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Checkout\PaymentMethods;
use App\Models\User;
use Illuminate\Support\Collection;
use Lunar\Models\Order;

/**
 * The invoices a customer still has to pay: their placed 30 day account orders that staff have not yet moved to
 * Payment Received. Card orders are paid when they are placed, so they are never open. Soonest due first.
 */
final class OpenInvoices
{
    /**
     * @return Collection<int, Invoice>
     */
    public function for(User $user): Collection
    {
        return Order::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('placed_at')
            ->where('meta->payment_method', PaymentMethods::ACCOUNT)
            ->where('status', '!=', 'payment-received')
            ->get()
            ->map(fn (Order $order): Invoice => new Invoice($order))
            ->sortBy(fn (Invoice $invoice): int => (int) $invoice->dueDate()?->getTimestamp())
            ->values();
    }
}
