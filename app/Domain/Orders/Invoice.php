<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Checkout\PaymentMethods;
use Carbon\CarbonInterface;
use Lunar\Models\Order;

/**
 * The invoice for a placed order. There is no invoices table: an order has exactly one invoice, so its
 * number, date and payment state are worked out from the order. The number is the order reference with
 * an INV- prefix (stable, unique and traceable), the date is when the order was placed, and a 30 day
 * account order is due 30 days later.
 */
final readonly class Invoice
{
    /** Days a 30 day account order is payable in. */
    public const int TERMS_DAYS = 30;

    public function __construct(public Order $order) {}

    public function number(): string
    {
        return 'INV-'.$this->order->reference;
    }

    public function date(): ?CarbonInterface
    {
        return $this->order->placed_at;
    }

    public function onAccount(): bool
    {
        return ($this->order->meta['payment_method'] ?? null) === PaymentMethods::ACCOUNT;
    }

    public function dueDate(): ?CarbonInterface
    {
        return $this->onAccount() ? $this->date()?->copy()->addDays(self::TERMS_DAYS) : null;
    }

    /** Staff move an order to "Payment Received" when the money is in; a card order is paid when it is placed. */
    public function isPaid(): bool
    {
        return ! $this->onAccount() || $this->order->status === 'payment-received';
    }

    /** An unpaid account invoice is overdue once its due day has passed. */
    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->dueDate()?->endOfDay()->isPast() === true;
    }

    public function paymentMethod(): string
    {
        return $this->onAccount() ? '30 day account' : 'Card';
    }

    public function paymentStatus(): string
    {
        if ($this->isPaid()) {
            return 'Paid';
        }

        $due = $this->dueDate()?->format('j F Y');

        return $this->isOverdue() ? 'Overdue, was due by '.$due : 'Payment due by '.$due;
    }
}
