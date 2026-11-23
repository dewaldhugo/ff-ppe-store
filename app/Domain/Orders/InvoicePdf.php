<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Barryvdh\DomPDF\Facade\Pdf;
use Lunar\Models\Order;

/**
 * The invoice of a placed order as a PDF, for staff in the admin (the customer's own invoice page is printed from the
 * browser). It shows what the invoice page shows, from the same App\Domain\Orders\Invoice, with the sender, bank and
 * registration details from config/store.php: nothing is worked out a second way, so the two never disagree.
 *
 * The PDF library cannot read CSS variables, so the few colours are here, equal to the design tokens in
 * resources/css/app.css (a test keeps them in step). The template keeps to tables and plain CSS 2.1 for the same reason.
 */
final class InvoicePdf
{
    /** The invoice's colours: the design tokens brand-blue, ink, ink-muted, border and surface-muted, and white. */
    public const array COLOURS = [
        'bar' => '#343A40',
        'bar_text' => '#FFFFFF',
        'ink' => '#222222',
        'muted' => '#555555',
        'border' => '#DADDE2',
        'head' => '#F8F8F8',
    ];

    /** Only a placed order has an invoice. */
    public function available(Order $order): bool
    {
        return $order->placed_at !== null;
    }

    /** The PDF file's bytes. */
    public function render(Order $order): string
    {
        $order->loadMissing(['lines', 'addresses', 'currency', 'customer']);

        return Pdf::loadView('admin.pdf.invoice', [
            'invoice' => new Invoice($order),
            'order' => $order,
            'colours' => self::COLOURS,
            'logo' => str_replace('\\', '/', public_path('images/frontline-logo.png')),
        ])->setPaper('a4')->output();
    }

    /** The file name: the invoice number with its slash made safe, like Invoice-10102026-001.pdf. */
    public function filename(Order $order): string
    {
        return 'Invoice-'.str_replace(['/', '\\'], '-', (new Invoice($order))->number()).'.pdf';
    }
}
