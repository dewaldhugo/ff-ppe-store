<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use App\Domain\Orders\DashboardFigures;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

/**
 * The tiles at the very top of the admin dashboard: income, orders, units sold, best seller, this month, the last
 * order, clients, the average monthly turnover and this month's gross profit (a row of its own). The figures are in DashboardFigures.
 *
 * Each tile is a solid colour with white text, in the old dashboard's four colours down the columns: green, red, blue
 * and orange. The colours are Filament's own (success, danger, info, warning at shade 700), set in
 * resources/views/admin/dashboard-tiles.blade.php, and the same tile is always the same colour.
 */
final class SalesTilesWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -100;

    protected ?string $pollingInterval = '60s';

    protected int|array|null $columns = ['default' => 1, 'sm' => 2, 'xl' => 4];

    /** The colour of each column, in order, repeating for the second row. */
    private const array COLOURS = ['success', 'danger', 'info', 'warning'];

    protected function getStats(): array
    {
        $figures = app(DashboardFigures::class);

        // A money tile carries its rand value as a small muted figure beside the pounds (only when STORE_ZAR_PER_GBP is set).
        $money = fn (int $pence): HtmlString => new HtmlString(
            e($figures->money($pence))
            .(($zar = $figures->zar($pence)) === null ? '' : ' <span class="sales-tile-zar" title="About '.e($zar).' at R'.e((string) config('store.zar_per_gbp')).' to the pound">'.e($zar).'</span>')
        );

        $tiles = [
            ['Total income (inc VAT)', $money($figures->incomePence())],
            ['Orders to date', number_format($figures->orderCount())],
            ['Total units sold', number_format($figures->unitsSold())],
            ['Best seller', $figures->bestSeller() ?? 'None yet'],
            ['Orders this month', number_format($figures->ordersThisMonth())],
            ['Last order placed', $figures->lastOrderDate() ?? 'None yet'],
            ['Clients', number_format($figures->clients())],
            ['Average turnover per month this year (ex VAT)', $money($figures->averageMonthlyTurnoverPence())],
            ['Gross profit this month (ex VAT, vendor cost taken off)', $money($figures->grossProfitThisMonthPence())],
        ];

        return array_map(
            fn (array $tile, int $index): Stat => Stat::make($tile[0], $tile[1])
                ->extraAttributes(['class' => 'sales-tile sales-tile--'.self::COLOURS[$index % count(self::COLOURS)].($index === count($tiles) - 1 ? ' sales-tile--wide' : '')]),
            $tiles,
            array_keys($tiles),
        );
    }
}
