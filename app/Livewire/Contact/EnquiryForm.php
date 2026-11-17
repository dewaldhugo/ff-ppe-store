<?php

declare(strict_types=1);

namespace App\Livewire\Contact;

use App\Mail\EnquiryReceived;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Throwable;

/**
 * The enquiry form on the Contact page. It validates, emails the store and
 * shows a confirmation. Spam: a hidden field that people never fill in, and a
 * limit on how many enquiries one address can send.
 */
final class EnquiryForm extends Component
{
    /** Enquiries one IP address may send in the window. */
    private const int MAX_ATTEMPTS = 5;

    private const int WINDOW_SECONDS = 3600;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $enquirySubject = '';

    public string $body = '';

    /** Honeypot. Hidden from people; a bot that fills every field fills this too. */
    public string $website = '';

    public bool $sent = false;

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s.]*$/'],
            'enquirySubject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'full name',
            'email' => 'email address',
            'phone' => 'phone number',
            'enquirySubject' => 'enquiry subject',
            'body' => 'message',
        ];
    }

    public function submit(): void
    {
        $this->sent = false;
        $data = $this->validate();

        // A bot filled the hidden field: look like success, send nothing.
        if ($this->website !== '') {
            $this->finish();

            return;
        }

        $key = 'enquiry:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->addError('form', 'You have sent several enquiries recently. Please try again later, or email us.');

            return;
        }

        $recipient = (string) config('store.enquiry_email');

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            report(new \RuntimeException('No valid enquiry recipient: set STORE_ENQUIRY_EMAIL or STORE_EMAIL.'));
            $this->addError('form', 'Sorry, we could not send your enquiry just now. Please try again later.');

            return;
        }

        try {
            Mail::to($recipient)->send(new EnquiryReceived(
                name: $data['name'],
                email: $data['email'],
                phone: ($data['phone'] ?? '') === '' ? null : $data['phone'],
                enquirySubject: $data['enquirySubject'],
                body: $data['body'],
            ));
        } catch (Throwable $e) {
            report($e);
            $this->addError('form', 'Sorry, we could not send your enquiry just now. Please try again later.');

            return;
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
        $this->finish();
    }

    private function finish(): void
    {
        $this->reset('name', 'email', 'phone', 'enquirySubject', 'body', 'website');
        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.contact.enquiry-form');
    }
}
