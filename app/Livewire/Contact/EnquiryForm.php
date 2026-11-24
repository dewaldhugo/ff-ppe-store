<?php

declare(strict_types=1);

namespace App\Livewire\Contact;

use App\Domain\Accounts\Industries;
use App\Domain\Enquiries\StaffMail;
use App\Domain\Enquiries\StaffMailOutcome;
use App\Mail\EnquiryReceived;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Component;

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

    /** An Industries slug, or empty when the visitor did not choose one. */
    public string $industry = '';

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
            'industry' => ['nullable', 'string', Rule::in(Industries::slugs())],
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
            'industry' => 'industry',
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

        // The same people as a new order, one email each (App\Domain\Enquiries\StaffMail).
        $outcome = app(StaffMail::class)->send(fn (): EnquiryReceived => new EnquiryReceived(
            name: $data['name'],
            email: $data['email'],
            phone: ($data['phone'] ?? '') === '' ? null : $data['phone'],
            enquirySubject: $data['enquirySubject'],
            body: $data['body'],
            industry: ($data['industry'] ?? '') === '' ? null : $data['industry'],
        ));

        if ($outcome !== StaffMailOutcome::Sent) {
            $this->addError('form', 'Sorry, we could not send your enquiry just now. Please try again later.');

            return;
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
        $this->finish();
    }

    private function finish(): void
    {
        $this->reset('name', 'email', 'phone', 'industry', 'enquirySubject', 'body', 'website');
        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.contact.enquiry-form');
    }
}
