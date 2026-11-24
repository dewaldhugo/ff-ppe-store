<?php

declare(strict_types=1);

namespace App\Livewire\WearTrials;

use App\Domain\Accounts\Industries;
use App\Domain\Enquiries\StaffMail;
use App\Domain\Enquiries\StaffMailOutcome;
use App\Mail\WearTrialRequested;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The request form on the Wear Trials page. It validates, emails the same people as a new order
 * (App\Domain\Enquiries\StaffMail) and shows a confirmation. Spam: a hidden field that people never fill in,
 * and a limit on how many requests one address can send.
 */
final class RequestForm extends Component
{
    /** Requests one IP address may send in the window. */
    private const int MAX_ATTEMPTS = 5;

    private const int WINDOW_SECONDS = 3600;

    public string $name = '';

    public string $company = '';

    public string $email = '';

    public string $phone = '';

    /** An Industries slug, or empty when the visitor did not choose one. */
    public string $industry = '';

    public string $products = '';

    public string $details = '';

    /** Honeypot. Hidden from people; a bot that fills every field fills this too. */
    public string $website = '';

    public bool $sent = false;

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'company' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s.]*$/'],
            'industry' => ['nullable', 'string', Rule::in(Industries::slugs())],
            'products' => ['required', 'string', 'max:300'],
            'details' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'full name',
            'company' => 'company name',
            'email' => 'email address',
            'phone' => 'phone number',
            'industry' => 'industry',
            'products' => 'products to trial',
            'details' => 'message',
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

        $key = 'wear-trial:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->addError('form', 'You have sent several requests recently. Please try again later, or email us.');

            return;
        }

        $outcome = app(StaffMail::class)->send(fn (): WearTrialRequested => new WearTrialRequested(
            name: $data['name'],
            company: $data['company'],
            email: $data['email'],
            phone: ($data['phone'] ?? '') === '' ? null : $data['phone'],
            industry: ($data['industry'] ?? '') === '' ? null : $data['industry'],
            products: $data['products'],
            details: $data['details'],
        ));

        if ($outcome !== StaffMailOutcome::Sent) {
            $this->addError('form', 'Sorry, we could not send your request just now. Please try again later.');

            return;
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
        $this->finish();
    }

    private function finish(): void
    {
        $this->reset('name', 'company', 'email', 'phone', 'industry', 'products', 'details', 'website');
        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.wear-trials.request-form');
    }
}
