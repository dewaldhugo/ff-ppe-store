<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use App\Admin\Extensions\DownloadInvoicePdfAction;
use App\Admin\ReportsAccess;
use App\Domain\Accounts\CustomerLogo;
use App\Domain\Orders\DashboardFigures;
use App\Domain\Orders\OrderLineProfit;
use App\Domain\Orders\ZarRate;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Lunar\Admin\Filament\Resources\OrderResource;
use Lunar\Models\Order;

/**
 * The list under the tiles at the top of the admin dashboard: every placed order, newest first, ten to a page, with the
 * company's logo, its invoice number, its gross profit in pounds and in rand and a download of its invoice as a PDF. Search covers the company, the order number
 * and the invoice number.
 */
final class RecentOrdersWidget extends TableWidget
{
    protected static ?int $sort = -99;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest orders')
            ->query(fn (): Builder => Order::query()
                ->select('lunar_orders.*', 'invoice_numbers.number as invoice_number')
                ->leftJoin('invoice_numbers', 'invoice_numbers.order_id', '=', 'lunar_orders.id')
                ->with(['customer', 'currency', 'lines' => fn ($lines) => $lines->where('type', 'physical')])
                ->whereNotNull('lunar_orders.placed_at')
                ->orderByDesc('lunar_orders.placed_at')
                ->orderByDesc('lunar_orders.id'))
            ->defaultSort(null)
            ->columns([
                TextColumn::make('row')->label('#')->rowIndex(),
                ImageColumn::make('logo')
                    ->label('')
                    ->circular()
                    ->imageSize(28)
                    ->getStateUsing(fn (Order $order): ?string => app(CustomerLogo::class)->url($order->customer)),
                TextColumn::make('company')
                    ->label('Company')
                    ->getStateUsing(fn (Order $order): string => (string) ($order->customer?->company_name ?: trim($order->customer?->first_name.' '.$order->customer?->last_name)))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('customer', fn (Builder $customer): Builder => $customer->where('company_name', 'like', '%'.$search.'%'))),
                TextColumn::make('placed_at')->label('Date')->date('Y-m-d'),
                TextColumn::make('reference')
                    ->label('Order no')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('lunar_orders.reference', 'like', '%'.$search.'%')),
                TextColumn::make('invoice_number')
                    ->label('Invoice no')
                    ->placeholder('')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('invoice_numbers.number', 'like', '%'.$search.'%')),
                TextColumn::make('total')
                    ->label('Value')
                    ->alignEnd()
                    ->getStateUsing(fn (Order $order): string => $order->total->formatted),
                // The order's gross profit before VAT (the goods less the vendor cost, OrderLineProfit), in pounds and in rand.
                TextColumn::make('gross_profit')
                    ->label('Gross profit (GBP)')
                    ->alignEnd()
                    ->getStateUsing(fn (Order $order): string => $this->figures()->money($this->profit($order))),
                // Rand at today's rate (ZarRate): the column is left out while the rate cannot be fetched.
                TextColumn::make('gross_profit_zar')
                    ->label('Gross profit (ZAR)')
                    ->alignEnd()
                    ->visible(fn (): bool => app(ZarRate::class)->perPound() !== null)
                    ->getStateUsing(fn (Order $order): ?string => $this->figures()->zar($this->profit($order))),
            ])
            // A row opens its order only for staff who manage orders; a Reports user (ReportsAccess) has the PDF and no link.
            ->recordUrl(fn (Order $order): ?string => ReportsAccess::canOpenOrders() ? OrderResource::getUrl('order', ['record' => $order]) : null)
            ->recordActions([DownloadInvoicePdfAction::make('Invoice')->size('sm')])
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->poll('60s');
    }

    private function figures(): DashboardFigures
    {
        return app(DashboardFigures::class);
    }

    private function profit(Order $order): int
    {
        return app(OrderLineProfit::class)->forOrder($order);
    }
}
