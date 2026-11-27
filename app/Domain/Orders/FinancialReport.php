<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Lunar\DataTypes\Price;
use Lunar\Models\Currency;
use Lunar\Models\Order;

/**
 * The yearly breakdown on the admin's Financial reporting pages. The orders counted are the dashboard's (DashboardFigures::orders:
 * placed, not failed or cancelled), in the year they were placed, and money is whole pence throughout.
 *
 * - Revenue and the monthly turnover are the orders' totals before VAT, delivery included.
 * - Gross profit is the goods lines' totals before VAT less what they cost (OrderLineProfit); delivery is left out, as its cost
 *   to the store is not known. The margin is that profit as a share of the goods lines' totals, to one decimal place.
 * - Units are the quantities on goods lines.
 *
 * @phpstan-type Year array{
 *     year: int,
 *     months: list<string>,
 *     turnover: list<int>,
 *     units: list<int>,
 *     profit: list<int>,
 *     revenue: int,
 *     grossProfit: int,
 *     unitsSold: int,
 *     margin: ?string,
 *     customers: list<array{label: string, units: int}>,
 *     styles: list<array{label: string, units: int}>,
 * }
 */
final class FinancialReport
{
    /** How many customers and styles the two "top" charts show. */
    public const int TOP = 10;

    public function __construct(
        private readonly DashboardFigures $figures,
        private readonly OrderLineProfit $lineProfit,
    ) {}

    /** The years that have an order, up to this year, oldest first; this year is always there. */
    public function years(): array
    {
        $first = $this->figures->orders()->min('placed_at');
        $firstYear = $first === null ? now()->year : min(now()->year, now()->parse($first)->year);

        return range($firstYear, now()->year);
    }

    /**
     * @return Year
     */
    public function forYear(int $year): array
    {
        // A year not over yet shows the months so far; an earlier year shows all twelve.
        $monthCount = $year === now()->year ? now()->month : 12;

        $turnover = array_fill(0, $monthCount, 0);
        $units = array_fill(0, $monthCount, 0);
        $profit = array_fill(0, $monthCount, 0);
        $goodsRevenue = 0;
        $customers = [];
        $styles = [];

        $start = now()->setYear($year)->startOfYear();

        $this->figures->orders()
            ->whereBetween('placed_at', [$start, $start->copy()->endOfYear()])
            ->with(['customer', 'lines' => fn ($lines) => $lines->where('type', 'physical')])
            ->lazyById(200)
            ->each(function (Order $order) use (&$turnover, &$units, &$profit, &$goodsRevenue, &$customers, &$styles, $monthCount): void {
                $month = $order->placed_at->month - 1;

                if ($month >= $monthCount) {
                    return;
                }

                $turnover[$month] += (int) $order->sub_total->value;
                $who = $this->customerLabel($order);

                foreach ($order->lines as $line) {
                    $quantity = (int) $line->quantity;
                    $units[$month] += $quantity;
                    $profit[$month] += $this->lineProfit->profit($line);
                    $goodsRevenue += $this->lineProfit->revenue($line);
                    $customers[$who] = ($customers[$who] ?? 0) + $quantity;
                    $styles[(string) $line->description] = ($styles[(string) $line->description] ?? 0) + $quantity;
                }
            });

        $grossProfit = array_sum($profit);

        return [
            'year' => $year,
            'months' => array_map(fn (int $month): string => now()->setDate($year, $month, 1)->format('M'), range(1, $monthCount)),
            'turnover' => $turnover,
            'units' => $units,
            'profit' => $profit,
            'revenue' => array_sum($turnover),
            'grossProfit' => $grossProfit,
            'unitsSold' => array_sum($units),
            'margin' => $goodsRevenue > 0 ? number_format(intdiv($grossProfit * 2000 + $goodsRevenue, $goodsRevenue * 2) / 10, 1).'%' : null,
            'customers' => $this->top($customers),
            'styles' => $this->top($styles),
        ];
    }

    public function money(int $pence): string
    {
        return (new Price($pence, Currency::getDefault(), 1))->formatted;
    }

    private function customerLabel(Order $order): string
    {
        $customer = $order->customer;

        if ($customer === null) {
            return 'No customer';
        }

        return trim((string) $customer->company_name) !== ''
            ? trim((string) $customer->company_name)
            : (trim($customer->first_name.' '.$customer->last_name) ?: 'No customer');
    }

    /**
     * @param  array<array-key, int>  $totals
     * @return list<array{label: string, units: int}>
     */
    private function top(array $totals): array
    {
        $rows = [];

        foreach ($totals as $label => $units) {
            $rows[] = ['label' => (string) $label, 'units' => $units];
        }

        usort($rows, fn (array $a, array $b): int => [$b['units'], $a['label']] <=> [$a['units'], $b['label']]);

        return array_slice($rows, 0, self::TOP);
    }
}
