<?php

declare(strict_types=1);

namespace App\Admin\Extensions;

use App\Domain\Orders\InvoicePdf;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Invoice PDF" button: on each row of the Invoices list and the dashboard's latest orders, and in the header of
 * the order page. It downloads the invoice of a placed order as a PDF (App\Domain\Orders\InvoicePdf). An order that has
 * not been placed has no invoice, so the button is hidden for it.
 */
final class DownloadInvoicePdfAction
{
    public static function make(string $label = 'Invoice PDF'): Action
    {
        return Action::make('download_invoice_pdf')
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (?Model $record, mixed $livewire): bool => ($order = DeleteOrderAction::order($record, $livewire)) !== null && app(InvoicePdf::class)->available($order))
            ->action(function (?Model $record, mixed $livewire) {
                $order = DeleteOrderAction::order($record, $livewire);

                abort_if($order === null || ! app(InvoicePdf::class)->available($order), 404);

                $pdf = app(InvoicePdf::class);

                return response()->streamDownload(
                    function () use ($pdf, $order): void {
                        echo $pdf->render($order);
                    },
                    $pdf->filename($order),
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }
}
