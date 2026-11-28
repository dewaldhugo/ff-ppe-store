<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A request for a quote, sent to the store (the same people as a new order). Replying goes to the customer. Sent at once, not
 * queued: the volume is a handful a day.
 */
final class QuoteRequested extends Mailable
{
    /**
     * @param  list<array{name: string, detail: ?string, quantity: ?string}>  $items  What the quote is for.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly ?string $company,
        public readonly array $items,
        public readonly ?string $quantity,
        public readonly ?string $body,
    ) {}

    public function envelope(): Envelope
    {
        $first = $this->items[0]['name'] ?? 'your products';
        $more = count($this->items) > 1 ? ' and '.(count($this->items) - 1).' more' : '';

        return new Envelope(
            // Newlines are stripped so a crafted name cannot add mail headers.
            subject: 'Quote request: '.trim((string) preg_replace('/\s+/', ' ', $first)).$more,
            replyTo: [new Address($this->email, trim((string) preg_replace('/\s+/', ' ', $this->name)))],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.quote-requested');
    }
}
