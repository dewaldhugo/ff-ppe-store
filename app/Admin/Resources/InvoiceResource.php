<?php

declare(strict_types=1);

namespace App\Admin\Resources;

use App\Admin\Extensions\DownloadInvoicePdfAction;
use App\Admin\ReportsAccess;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Domain\Checkout\PaymentMethods;
use App\Domain\Orders\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Lunar\Admin\Filament\Resources\OrderResource;
use Lunar\Models\Order;

/**
 * Invoices in the admin: every placed order has one (there is no invoices table, App\Domain\Orders\Invoice works it out
 * from the order), so this lists the placed orders, with the invoice number, date, company, payment state and total. Each row opens its order and downloads the invoice as a PDF. It is read-only, and is seen by
 * the staff who see orders (sales:manage-orders) and staff with the Reports role (ReportsAccess).
 */
final class InvoiceResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'invoices';

    protected static ?string $modelLabel = 'invoice';

    protected static ?string $pluralModelLabel = 'invoices';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-document-text';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lunarpanel::global.sections.sales');
    }

    public static function canViewAny(): bool
    {
        return ReportsAccess::allowed();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Every placed order, with its invoice number. The number is given when the order is placed, or the first time the
     * invoice is looked at (InvoiceNumbers::for), so an order that has none yet still shows, with the number blank. A
     * number kept for a deleted order has no order and so no row.
     */
    public static function getEloquentQuery(): Builder
    {
        return Order::query()
            ->select('lunar_orders.*', 'invoice_numbers.number as invoice_number')
            ->leftJoin('invoice_numbers', 'invoice_numbers.order_id', '=', 'lunar_orders.id')
            ->with(['customer', 'currency'])
            ->whereNotNull('lunar_orders.placed_at');
    }

    public static function table(Table $table): Table
    {
        // An account order still owing: on account terms and not yet moved to Payment Received.
        $account = PaymentMethods::ACCOUNT;
        $unpaid = fn (Builder $query): Builder => $query
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(lunar_orders.meta, '$.payment_method')), '') = ?", [$account])
            ->where('lunar_orders.status', '!=', 'payment-received');

        return $table
            ->defaultSort('lunar_orders.placed_at', 'desc')
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice no')
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('invoice_numbers.number', 'like', '%'.$search.'%')),
                TextColumn::make('placed_at')->label('Date')->date('j M Y')->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('lunar_orders.placed_at', $direction)),
                TextColumn::make('company')
                    ->label('Company')
                    ->getStateUsing(fn (Order $order): string => (string) ($order->customer?->company_name ?: trim($order->customer?->first_name.' '.$order->customer?->last_name)))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('customer', fn (Builder $customer): Builder => $customer->where('company_name', 'like', '%'.$search.'%'))),
                TextColumn::make('reference')
                    ->label('Order no')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('lunar_orders.reference', 'like', '%'.$search.'%')),
                TextColumn::make('customer_reference')
                    ->label('PO number')
                    ->placeholder('')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('lunar_orders.customer_reference', 'like', '%'.$search.'%')),
                TextColumn::make('payment')
                    ->label('Payment')
                    ->badge()
                    ->getStateUsing(fn (Order $order): string => (new Invoice($order))->paymentStatus())
                    ->color(fn (Order $order): string => (new Invoice($order))->isPaid() ? 'success' : ((new Invoice($order))->isOverdue() ? 'danger' : 'warning')),
                TextColumn::make('total')
                    ->label('Total (inc VAT)')
                    ->alignEnd()
                    ->getStateUsing(fn (Order $order): string => $order->total->formatted),
            ])
            ->filters([
                SelectFilter::make('payment')
                    ->label('Payment')
                    ->options(['paid' => 'Paid', 'unpaid' => 'Not yet paid', 'overdue' => 'Overdue'])
                    ->query(function (Builder $query, array $data) use ($unpaid): Builder {
                        return match ($data['value'] ?? null) {
                            'unpaid' => $unpaid($query),
                            'overdue' => $unpaid($query)->where('lunar_orders.placed_at', '<', now()->subDays(Invoice::TERMS_DAYS)->startOfDay()),
                            'paid' => $query->where(fn (Builder $paid): Builder => $paid
                                ->where('lunar_orders.status', 'payment-received')
                                ->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(lunar_orders.meta, '$.payment_method')), '') <> ?", [PaymentMethods::ACCOUNT])),
                            default => $query,
                        };
                    }),
                Filter::make('date')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('lunar_orders.placed_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('lunar_orders.placed_at', '<=', $date))),
            ])
            // A row opens its order only for staff who manage orders; a Reports user (ReportsAccess) has the PDF and no link.
            ->recordUrl(fn (Order $order): ?string => ReportsAccess::canOpenOrders() ? OrderResource::getUrl('order', ['record' => $order]) : null)
            ->recordActions([DownloadInvoicePdfAction::make('PDF')])
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
