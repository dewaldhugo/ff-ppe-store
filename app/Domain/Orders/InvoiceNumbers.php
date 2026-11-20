<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Lunar\Models\Order;

/**
 * Invoice numbers: the date the order was placed as DDMMYYYY, a slash, and that day's sequence
 * from 001 (10102026/001, 10102026/002, then 11102026/001). One per placed order, given when the
 * order is placed (AssignInvoiceNumber) and never changed afterwards, so it stays the same if the
 * order's date is edited later. Kept in `invoice_numbers`, whose unique keys stop two orders sharing
 * a number even when two are placed at the same moment.
 */
final class InvoiceNumbers
{
    /** Tries before giving up when another order takes the same sequence first. */
    private const int ATTEMPTS = 8;

    /** The order's invoice number, given now if it has none yet. */
    public function for(Order $order): string
    {
        $number = DB::table('invoice_numbers')->where('order_id', $order->id)->value('number');

        return is_string($number) ? $number : $this->assign($order);
    }

    public function has(Order $order): bool
    {
        return DB::table('invoice_numbers')->where('order_id', $order->id)->exists();
    }

    /**
     * Gives a placed order its number (or returns the one it has).
     *
     * @throws LogicException when the order is not placed: a draft has no invoice
     */
    public function assign(Order $order): string
    {
        if ($order->placed_at === null) {
            throw new LogicException('An order that is not placed has no invoice number.');
        }

        $date = $order->placed_at->toDateString();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($order, $date): string {
                    $existing = DB::table('invoice_numbers')->where('order_id', $order->id)->value('number');

                    if (is_string($existing)) {
                        return $existing;
                    }

                    $sequence = ((int) DB::table('invoice_numbers')->where('invoice_date', $date)->max('sequence')) + 1;
                    $number = $order->placed_at->format('dmY').'/'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);

                    DB::table('invoice_numbers')->insert([
                        'order_id' => $order->id,
                        'invoice_date' => $date,
                        'sequence' => $sequence,
                        'number' => $number,
                        'created_at' => now(),
                    ]);

                    return $number;
                });
            } catch (UniqueConstraintViolationException $exception) {
                // Another order took this sequence a moment ago: look again.
                if ($attempt === self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new LogicException('Unreachable.');
    }
}
