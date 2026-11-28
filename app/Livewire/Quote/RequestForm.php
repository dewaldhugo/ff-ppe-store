<?php

declare(strict_types=1);

namespace App\Livewire\Quote;

use App\Domain\Cart\CustomerCart;
use App\Domain\Enquiries\StaffMail;
use App\Domain\Enquiries\StaffMailOutcome;
use App\Mail\QuoteRequested;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Lunar\Models\Product;

/**
 * The Request a quote form: for larger quantities than the price list covers. It is for one product (`?product=12`, from the
 * product page) or for the signed-in customer's cart (`?cart=1`, from the cart page), or for neither, when the customer says what
 * they need in the message. It emails the same people as a new order (StaffMail) and shows a confirmation. Spam: a hidden field and
 * a limit on how many requests one address can send.
 */
final class RequestForm extends Component
{
    private const int MAX_ATTEMPTS = 5;

    private const int WINDOW_SECONDS = 3600;

    /** What the quote is for, fixed when the page opens (a product, or the cart's lines). */
    #[Locked]
    public array $items = [];

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $company = '';

    /** For a single product: how many, in the customer's words ("50 pairs, sizes 7 to 11"). */
    public string $quantity = '';

    public string $body = '';

    /** Honeypot. Hidden from people; a bot that fills every field fills this too. */
    public string $website = '';

    public bool $sent = false;

    public function mount(): void
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            $this->name = (string) $user->name;
            $this->email = (string) $user->email;
        }

        $this->items = $this->itemsFromRequest();
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s.]*$/'],
            'company' => ['nullable', 'string', 'max:150'],
            'quantity' => ['nullable', 'string', 'max:150'],
            // Without a product or a cart the message is what the quote is for.
            'body' => [$this->items === [] ? 'required' : 'nullable', 'string', 'min:10', 'max:3000'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return ['name' => 'full name', 'email' => 'email address', 'phone' => 'phone number', 'company' => 'company name', 'quantity' => 'quantity', 'body' => 'message'];
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

        $key = 'quote:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->addError('form', 'You have sent several quote requests recently. Please try again later, or email us.');

            return;
        }

        $outcome = app(StaffMail::class)->send(fn (): QuoteRequested => new QuoteRequested(
            name: $data['name'],
            email: $data['email'],
            phone: ($data['phone'] ?? '') === '' ? null : $data['phone'],
            company: ($data['company'] ?? '') === '' ? null : $data['company'],
            items: $this->items,
            quantity: ($data['quantity'] ?? '') === '' ? null : $data['quantity'],
            body: ($data['body'] ?? '') === '' ? null : $data['body'],
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
        $this->reset('phone', 'company', 'quantity', 'body', 'website');
        $this->sent = true;
    }

    /**
     * @return list<array{name: string, detail: ?string, quantity: ?string}>
     */
    private function itemsFromRequest(): array
    {
        $productId = (int) request()->query('product', 0);

        if ($productId > 0) {
            $product = Product::query()->where('status', 'published')->find($productId);

            return $product === null ? [] : [['name' => (string) $product->translateAttribute('name'), 'detail' => null, 'quantity' => null]];
        }

        if (request()->boolean('cart') && Auth::guard('web')->check()) {
            $cart = app(CustomerCart::class)->current();

            if ($cart === null) {
                return [];
            }

            $cart->load('lines.purchasable.product', 'lines.purchasable.values.option');

            return $cart->lines->map(function ($line): array {
                $variant = $line->purchasable;
                $options = $variant->values->map(fn ($value): string => (string) $value->translate('name'))->implode(', ');

                return [
                    'name' => (string) $variant->product->translateAttribute('name'),
                    'detail' => trim($options.($variant->sku ? ' '.$variant->sku : '')) ?: null,
                    'quantity' => (string) $line->quantity,
                ];
            })->values()->all();
        }

        return [];
    }

    public function render(): View
    {
        return view('livewire.quote.request-form');
    }
}
