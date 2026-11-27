<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lunar\DataTypes\Price;
use Lunar\Models\Currency;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

/**
 * The figures on the tiles at the top of the admin dashboard. An order counts once it is placed, unless it failed or
 * was cancelled; an order on 30 day terms that is still awaiting payment counts, because it has been invoiced.
 * Money is whole pence from the order totals, formatted at the end, never worked out as a float.
 */
final class DashboardFigures
{
    /** Statuses that take an order out of every figure. */
    private const array NOT_COUNTED = ['failed', 'cancelled'];

    /** @return Builder<Order> */
    public function orders(): Builder
    {
        return Order::query()->whereNotNull('placed_at')->whereNotIn('status', self::NOT_COUNTED);
    }

    /** Everything invoiced to date, VAT included. */
    public function income(): string
    {
        return $this->money((int) $this->orders()->sum('total'));
    }

    public function orderCount(): int
    {
        return $this->orders()->count();
    }

    /** Pairs and items sold: the quantity on every goods line (delivery lines are not goods). */
    public function unitsSold(): int
    {
        return (int) OrderLine::query()
            ->where('type', 'physical')
            ->whereIn('order_id', $this->orders()->select('id'))
            ->sum('quantity');
    }

    /** The description of the line sold in the greatest quantity, or null before the first sale. */
    public function bestSeller(): ?string
    {
        $row = OrderLine::query()
            ->where('type', 'physical')
            ->whereIn('order_id', $this->orders()->select('id'))
            ->select('description', DB::raw('SUM(quantity) as units'))
            ->groupBy('description')
            ->orderByDesc('units')
            ->orderBy('description')
            ->first();

        return $row?->description;
    }

    public function ordersThisMonth(): int
    {
        return $this->orders()->whereBetween('placed_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
    }

    /** The day the latest order was placed, like "21 Nov" (with the year when it was not this year). */
    public function lastOrderDate(): ?string
    {
        $placed = $this->orders()->max('placed_at');

        if ($placed === null) {
            return null;
        }

        $date = now()->parse($placed);

        return $date->isCurrentYear() ? $date->format('j M') : $date->format('j M Y');
    }

    public function clients(): int
    {
        return Customer::query()->count();
    }

    /**
     * Turnover this year (before VAT) divided by the months of the year so far, the current month counted as a whole.
     * Worked out in whole pence, rounded half up.
     */
    public function averageMonthlyTurnover(): string
    {
        $net = (int) $this->orders()->whereBetween('placed_at', [now()->startOfYear(), now()->endOfYear()])->sum('sub_total');
        $months = max(1, now()->month);

        return $this->money(intdiv($net * 2 + $months, $months * 2));
    }

    /**
     * This month's gross profit, before VAT: what the goods on the month's invoices sold for (each line's total ex VAT, after any
     * discount) less what they cost. The cost is the vendor cost copied onto the line when the order was placed (`cost_price`
     * in the line's meta, per unit), so a later price change does not move it. A line with no recorded cost (a hand-made variant,
     * an order from before the snapshot) has the markup taken off its price instead: cost = price / markup, the vendor's markup
     * or the default one. Delivery lines are not goods and are left out, as their cost to the store is not known.
     * An order counts as it does in every other figure here, and the month is the one it was placed in.
     */
    public function grossProfitThisMonthPence(): int
    {
        $profit = 0;

        OrderLine::query()
            ->where('type', 'physical')
            ->whereIn('order_id', $this->orders()->whereBetween('placed_at', [now()->startOfMonth(), now()->endOfMonth()])->select('id'))
            ->select(['id', 'quantity', 'sub_total', 'meta'])
            ->lazyById(500)
            ->each(function (OrderLine $line) use (&$profit): void {
                $revenue = (int) $line->sub_total->value;
                $meta = (array) $line->meta;
                $recorded = $meta['cost_price'] ?? null;

                $cost = is_numeric($recorded)
                    ? (int) $recorded * (int) $line->quantity
                    : $this->costFromMarkup($revenue, (string) ($meta['vendor_code'] ?? ''));

                $profit += $revenue - $cost;
            });

        return $profit;
    }

    public function grossProfitThisMonth(): string
    {
        return $this->money($this->grossProfitThisMonthPence());
    }

    /** A price ex VAT with the markup taken off, in whole pence, rounded half up (the markup in thousandths, so no float touches the money). */
    private function costFromMarkup(int $pence, string $vendorCode): int
    {
        $markup = config('vendorsync.vendors.'.strtoupper($vendorCode).'.markup') ?? config('vendorsync.markup');
        $thousandths = is_numeric($markup) && (float) $markup >= 1.0 ? (int) round((float) $markup * 1000) : 1500;

        return intdiv($pence * 2000 + $thousandths, $thousandths * 2);
    }

    private function money(int $pence): string
    {
        return (new Price($pence, Currency::getDefault(), 1))->formatted;
    }
}
