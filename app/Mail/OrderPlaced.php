<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Orders\Invoice;
use App\Domain\Orders\LineDetails;
use App\Support\EmailColours;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Lunar\Admin\Filament\Resources\OrderResource;
use Lunar\Models\Order;
use Lunar\Models\OrderAddress;
use Throwable;

/**
 * Tells the store's staff that a customer has placed an order (`STORE_ORDER_NOTIFICATION_EMAILS`,
 * docs/cart.md): who, what, how it is paid and a link to the order in the admin. Plain text, queued
 * on the default queue, so a slow or failing mail server never holds up the customer's checkout. It is
 * sent as HTML with a plain text part (`mail.order-placed` and `mail.order-placed-text`).
 */
final class OrderPlaced extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Order $order)
    {
        $this->onQueue(Queues::DEFAULT);
    }

    public function envelope(): Envelope
    {
        $order = $this->loaded();
        $company = $this->oneLine($this->company($order->billingAddress));

        return new Envelope(
            // One line, so a crafted company name cannot add mail headers.
            subject: 'New order '.$order->reference.($company === '' ? '' : ' from '.$company).' ('.$order->total->formatted(trimTrailingZeros: false).')',
        );
    }

    public function content(): Content
    {
        $order = $this->loaded();
        $invoice = new Invoice($order);
        $billing = $order->billingAddress;
        $shipping = $order->shippingAddress;
        $onAccount = $invoice->onAccount();

        $lines = $this->lines($order);

        return new Content(
            html: 'mail.order-placed',
            text: 'mail.order-placed-text',
            with: [
                'reference' => $order->reference,
                'invoiceNumber' => $invoice->number(),
                'placed' => $order->placed_at?->format('j M Y H:i'),
                'payment' => $onAccount
                    ? $invoice->paymentMethodLabel().', due '.$invoice->dueDate()?->format('j M Y')
                    : $invoice->paymentMethodLabel().', paid',
                'poNumber' => filled($order->customer_reference) ? (string) $order->customer_reference : null,
                'supplierNumber' => $invoice->supplierNumber(),
                'company' => $this->company($billing),
                'contactName' => (string) ($order->meta['contact_name'] ?? trim($billing?->first_name.' '.$billing?->last_name)),
                'contactEmail' => (string) ($order->meta['contact_email'] ?? $billing?->contact_email),
                'contactPhone' => (string) ($order->meta['contact_phone'] ?? $billing?->contact_phone),
                'vatNumber' => filled($billing?->tax_identifier) ? (string) $billing->tax_identifier : null,
                'deliverTo' => $this->addressLines($shipping),
                'billTo' => $this->addressLines($billing),
                'instructions' => filled($order->notes) ? (string) $order->notes : null,
                'items' => $this->items($lines),
                'lines' => $lines,
                'subtotal' => Invoice::amount($invoice->subtotal()),
                'discount' => $order->discount_total->value > 0 ? Invoice::amount($order->discount_total) : null,
                'delivery' => Invoice::amount($invoice->delivery()),
                'vat' => Invoice::amount($order->tax_total),
                'total' => $order->total->formatted(trimTrailingZeros: false),
                'adminUrl' => $this->adminUrl($order),
                // For the HTML part only: the site's design tokens as hex values, its name and its logo.
                'colours' => EmailColours::all(),
                'siteName' => (string) config('app.name'),
                'logoUrl' => asset('images/frontline-logo-white.png'),
            ],
        );
    }

    private function loaded(): Order
    {
        return $this->order->loadMissing(['lines', 'shippingAddress', 'billingAddress']);
    }

    private function company(?OrderAddress $address): string
    {
        return filled($address?->company_name) ? (string) $address->company_name : trim($address?->first_name.' '.$address?->last_name);
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @return list<string>
     */
    private function addressLines(?OrderAddress $address): array
    {
        if ($address === null) {
            return [];
        }

        return array_values(array_filter([
            $address->company_name,
            trim($address->first_name.' '.$address->last_name),
            $address->line_one,
            $address->line_two,
            $address->city,
            $address->state,
            $address->postcode,
        ], fn (mixed $line): bool => filled($line)));
    }

    /**
     * The goods lines, one row each: quantity, description, colour and sizes as one string, code, unit price
     * and amount ex VAT. The HTML part lays them out as a table and the text part (items()) as one line each.
     *
     * @return list<array{quantity: int, description: string, details: string, code: string, unit: string, amount: string}>
     */
    private function lines(Order $order): array
    {
        $rows = [];

        foreach ($order->lines->where('type', 'physical') as $line) {
            $details = LineDetails::parts($line);

            $rows[] = [
                'quantity' => (int) $line->quantity,
                'description' => (string) $line->description,
                'details' => implode(', ', $details),
                'code' => (string) $line->identifier,
                'unit' => Invoice::amount($line->unit_price),
                'amount' => Invoice::amount($line->sub_total),
            ];
        }

        return $rows;
    }

    /**
     * The same rows as single lines of text, for the plain text part.
     *
     * @param  list<array{quantity: int, description: string, details: string, code: string, unit: string, amount: string}>  $lines
     * @return list<string>
     */
    private function items(array $lines): array
    {
        return array_map(fn (array $line): string => sprintf(
            '%d x %s%s (code %s): %s each, %s',
            $line['quantity'],
            $line['description'],
            $line['details'] === '' ? '' : ', '.$line['details'],
            $line['code'],
            $line['unit'],
            $line['amount'],
        ), $lines);
    }

    /** The order in the admin panel, or the admin's front page if the link cannot be built. */
    private function adminUrl(Order $order): string
    {
        try {
            return OrderResource::getUrl('order', ['record' => $order], panel: 'lunar');
        } catch (Throwable) {
            return url('/admin');
        }
    }
}
