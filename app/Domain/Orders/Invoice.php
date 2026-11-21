<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Checkout\PaymentMethods;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Lunar\DataTypes\Price;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

/**
 * The invoice for a placed order. There is no invoices table: an order has exactly one invoice, so its
 * date and payment state are worked out from the order. The number is kept apart (InvoiceNumbers: the
 * date placed and a daily sequence, such as 10102026/001), the date is when the order was placed, and a
 * 30 day account order is due 30 days later.
 */
final readonly class Invoice
{
    /** Days a 30 day account order is payable in. */
    public const int TERMS_DAYS = 30;

    public function __construct(public Order $order) {}

    public function number(): string
    {
        return app(InvoiceNumbers::class)->for($this->order);
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

    /** Still to be paid by bank transfer: the bank details and the payment advice slip go with it. */
    public function needsPayment(): bool
    {
        return ! $this->isPaid();
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

    /** The payment method as the invoice words it: "Credit Card" or "30 Day Account". */
    public function paymentMethodLabel(): string
    {
        return $this->onAccount() ? '30 Day Account' : 'Credit Card';
    }

    /** The customer's number for the store in their own purchasing system, when they gave one. */
    public function supplierNumber(): ?string
    {
        $number = $this->order->meta['supplier_number'] ?? null;

        return filled($number) ? (string) $number : null;
    }

    public function paymentStatus(): string
    {
        if ($this->isPaid()) {
            return 'Paid';
        }

        $due = $this->dueDate()?->format('j F Y');

        return $this->isOverdue() ? 'Overdue, was due by '.$due : 'Payment due by '.$due;
    }

    /** The goods, in the order they were added. */
    public function goodsLines(): Collection
    {
        return $this->order->lines->where('type', 'physical')->values();
    }

    /** The delivery charge lines (one, or none). */
    public function deliveryLines(): Collection
    {
        return $this->order->lines->where('type', 'shipping')->values();
    }

    /** Delivery, ex VAT. */
    public function delivery(): Price
    {
        return $this->price((int) $this->deliveryLines()->sum(fn (OrderLine $line): int => $line->sub_total->value));
    }

    /** What the order comes to ex VAT after discounts and with delivery. */
    public function netTotal(): Price
    {
        return $this->price($this->order->total->value - $this->order->tax_total->value);
    }

    /**
     * The goods ex VAT before any discount. Worked out from the net total, so the rows
     * (subtotal, discount, delivery, VAT, total) always add up to the total.
     */
    public function subtotal(): Price
    {
        return $this->price($this->netTotal()->value - $this->delivery()->value + $this->order->discount_total->value);
    }

    /**
     * The VAT rate of a line as a plain number (20, 0, 5.5).
     */
    public function rate(OrderLine $line): float
    {
        return (float) $line->tax_breakdown->amounts->sum('percentage');
    }

    /** The one VAT rate on every line, for the "TOTAL VAT 20%" row, or null when the rates are mixed. */
    public function singleRate(): ?float
    {
        $rates = $this->order->lines->map(fn (OrderLine $line): float => $this->rate($line))->unique()->values();

        return $rates->count() === 1 ? (float) $rates->first() : null;
    }

    /**
     * An amount as a plain number with no currency sign: 27.90, 1,234.50, -5.00. The invoice shows the
     * pound sign on the total only. Worked out from whole pence, never a float.
     */
    public static function amount(Price $price): string
    {
        $pence = abs($price->value);

        return ($price->value < 0 ? '-' : '').number_format(intdiv($pence, 100)).'.'.str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    }

    private function price(int $value): Price
    {
        return new Price($value, $this->order->currency, 1);
    }
}
