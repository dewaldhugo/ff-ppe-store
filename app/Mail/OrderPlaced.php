<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Orders\Invoice;
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
 * on the default queue, so a slow or failing mail server never holds up the customer's checkout.
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

        return new Content(
            text: 'mail.order-placed',
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
                'items' => $this->items($order),
                'subtotal' => Invoice::amount($invoice->subtotal()),
                'discount' => $order->discount_total->value > 0 ? Invoice::amount($order->discount_total) : null,
                'delivery' => Invoice::amount($invoice->delivery()),
                'vat' => Invoice::amount($order->tax_total),
                'total' => $order->total->formatted(trimTrailingZeros: false),
                'adminUrl' => $this->adminUrl($order),
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
     * One line per goods line: quantity, description, colour and sizes, code, unit price and amount ex VAT.
     *
     * @return list<string>
     */
    private function items(Order $order): array
    {
        $items = [];

        foreach ($order->lines->where('type', 'physical') as $line) {
            $details = array_values(array_filter([
                $line->meta['colour'] ?? null,
                filled($line->meta['size'] ?? null) ? (($line->meta['size_is_uk'] ?? false) ? 'UK ' : '').$line->meta['size'] : null,
                filled($line->meta['eu_size'] ?? null) ? 'EU '.$line->meta['eu_size'] : null,
            ], fn (mixed $detail): bool => filled($detail)));

            $items[] = sprintf(
                '%d x %s%s (code %s): %s each, %s',
                $line->quantity,
                $line->description,
                $details === [] ? '' : ', '.implode(', ', $details),
                $line->identifier,
                Invoice::amount($line->unit_price),
                Invoice::amount($line->sub_total),
            );
        }

        return $items;
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
