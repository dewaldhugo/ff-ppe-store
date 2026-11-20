<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An enquiry from the Contact page, sent to the store. Replying goes to the
 * customer. Sent at once, not queued: the volume is a handful a day.
 */
final class EnquiryReceived extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly string $enquirySubject,
        public readonly string $body,
        /** An Industries slug, or null when none was chosen. */
        public readonly ?string $industry = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Newlines are stripped so a crafted subject cannot add mail headers.
            subject: 'Website enquiry: '.trim((string) preg_replace('/\s+/', ' ', $this->enquirySubject)),
            replyTo: [new Address($this->email, trim((string) preg_replace('/\s+/', ' ', $this->name)))],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.enquiry-received');
    }
}
