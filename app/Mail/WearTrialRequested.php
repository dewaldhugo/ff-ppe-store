<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A wear trial request from the Wear Trials page, sent to the store. Replying goes to the customer.
 * Sent at once, not queued: the volume is a handful a day.
 */
final class WearTrialRequested extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $company,
        public readonly string $email,
        public readonly ?string $phone,
        /** An Industries slug, or null when none was chosen. */
        public readonly ?string $industry,
        public readonly string $products,
        public readonly string $details,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Newlines are stripped so a crafted company name cannot add mail headers.
            subject: 'Wear trial request: '.trim((string) preg_replace('/\s+/', ' ', $this->company)),
            replyTo: [new Address($this->email, trim((string) preg_replace('/\s+/', ' ', $this->name)))],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.wear-trial-requested');
    }
}
