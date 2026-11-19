<?php

declare(strict_types=1);

namespace App\Livewire\Checkout;

use App\Domain\Accounts\Postcode;
use App\Domain\Accounts\VatNumber;
use App\Domain\Cart\CartLineViews;
use App\Domain\Cart\CartSummary;
use App\Domain\Cart\CustomerCart;
use App\Domain\Cart\Delivery\DeliveryPolicy;
use App\Domain\Checkout\CardPayments;
use App\Domain\Checkout\CheckoutRejected;
use App\Domain\Checkout\PaymentMethods;
use App\Domain\Checkout\PlaceOrder;
use App\Domain\Pricing\DisplayPrice;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Lunar\Models\Cart;

/**
 * The checkout (/checkout), for signed-in customers with something in their cart.
 *
 * Left: who is buying, where it goes, delivery and payment. Right: the live order summary
 * with VAT on every line and the full money stack. Lunar prices everything (CartSummary
 * reads its totals); this component only holds what the customer types, and PlaceOrder
 * turns it into an order.
 */
final class CheckoutPage extends Component
{
    public string $full_name = '';

    public string $email = '';

    public string $phone = '';

    public string $company_name = '';

    public string $vat_number = '';

    public string $po_number = '';

    public string $notes = '';

    /** `commercial` or `residential`: couriers treat them differently. */
    public string $site_type = 'commercial';

    public string $ship_line_one = '';

    public string $ship_line_two = '';

    public string $ship_city = '';

    public string $ship_county = '';

    public string $ship_postcode = '';

    public bool $billing_same = true;

    public string $bill_line_one = '';

    public string $bill_line_two = '';

    public string $bill_city = '';

    public string $bill_county = '';

    public string $bill_postcode = '';

    /** One of PaymentMethods::available() for this customer, or empty when none can be used. */
    public string $payment_method = '';

    public string $promo_code = '';

    public ?string $promo_applied = null;

    public ?string $promoError = null;

    /** Why the order could not be placed, when it is not about one field. */
    public ?string $formError = null;

    public function mount(CustomerCart $cart): void
    {
        $current = $cart->current();

        if ($current === null || $current->lines->isEmpty()) {
            $this->redirectRoute('cart');

            return;
        }

        $this->promo_applied = $current->coupon_code;

        // A card payment that did not go through sends the customer back here with the reason.
        $this->formError = session()->pull('checkout_error');

        $user = Auth::guard('web')->user();
        $customer = $user->latestCustomer();
        $address = $customer?->addresses()->where('billing_default', true)->first() ?? $customer?->addresses()->first();

        $this->payment_method = PaymentMethods::default($user);
        $this->full_name = (string) $user->name;
        $this->email = (string) $user->email;
        $this->phone = (string) ($user->phone ?? '');
        $this->company_name = (string) ($customer?->company_name ?? '');
        $this->vat_number = (string) ($customer?->tax_identifier ?? '');

        // The company address given at registration is where most orders go: a starting point to confirm.
        if ($address !== null) {
            $this->ship_line_one = (string) $address->line_one;
            $this->ship_line_two = (string) $address->line_two;
            $this->ship_city = (string) $address->city;
            $this->ship_county = (string) $address->state;
            $this->ship_postcode = (string) $address->postcode;
        }
    }

    public function updated(string $property): void
    {
        $this->formError = null;

        match ($property) {
            'ship_postcode' => $this->ship_postcode = Postcode::normalise($this->ship_postcode) ?? '',
            'bill_postcode' => $this->bill_postcode = Postcode::normalise($this->bill_postcode) ?? '',
            'vat_number' => $this->vat_number = VatNumber::normalise($this->vat_number) ?? '',
            default => null,
        };

        if (array_key_exists($property, $this->rules())) {
            $this->validateOnly($property);
        }
    }

    public function applyPromo(CustomerCart $cart): void
    {
        $this->promoError = null;
        $code = strtoupper(trim($this->promo_code));
        $current = $cart->current();

        if ($code === '' || $current === null) {
            $this->promoError = 'Enter a code.';

            return;
        }

        // Lunar applies a discount whose coupon matches. A code that changes nothing is refused.
        $current->update(['coupon_code' => $code]);
        $current->recalculate();

        if ((int) $current->discountTotal?->value === 0) {
            $current->update(['coupon_code' => null]);
            $current->recalculate();
            $this->promoError = 'That code is not valid for this order.';

            return;
        }

        $this->promo_applied = $code;
        $this->promo_code = '';
    }

    public function removePromo(CustomerCart $cart): void
    {
        $current = $cart->current();
        $current?->update(['coupon_code' => null]);
        $current?->recalculate();

        $this->promo_applied = null;
        $this->promoError = null;
    }

    /**
     * Places an order on 30 day account terms. Card orders go through startCardPayment() instead:
     * the Place order button runs it for them, so this is only reached by a form posted without it.
     */
    public function placeOrder(PlaceOrder $placeOrder): mixed
    {
        $this->formError = null;
        $this->tidy();

        $this->validate();

        if ($this->payment_method !== PaymentMethods::ACCOUNT) {
            $this->formError = 'Please use the Pay by card button to pay for this order.';

            return null;
        }

        try {
            $order = $placeOrder(Auth::guard('web')->user(), $this->details());
        } catch (CheckoutRejected $rejected) {
            $this->formError = $rejected->getMessage();

            return null;
        }

        return $this->redirectRoute('checkout.complete', $order->reference);
    }

    /**
     * Step one of a card payment, called by the browser after the card form has validated: checks
     * the checkout, builds the draft order and the Stripe payment intent, and hands back what Stripe
     * needs to take the payment. Null when something needs fixing (the errors are shown on the page).
     *
     * @return array{client_secret: string, return_url: string, order_reference: string}|null
     */
    public function startCardPayment(PlaceOrder $placeOrder): ?array
    {
        $this->formError = null;
        $this->tidy();

        $this->validate();

        try {
            return $placeOrder->startCard(Auth::guard('web')->user(), $this->details());
        } catch (CheckoutRejected $rejected) {
            $this->formError = $rejected->getMessage();

            return null;
        }
    }

    /**
     * The cart's current total in pence, so the card form shows the right amount for wallets
     * before the payment is confirmed.
     */
    public function cardAmount(CustomerCart $customerCart, CartSummary $summary, CardPayments $card): int
    {
        $cart = $customerCart->current();

        if ($cart === null || $cart->lines->isEmpty()) {
            return 0;
        }

        return $card->amount($this->deliveryReady() ? $summary->withDelivery($cart) : $cart->recalculate());
    }

    private function tidy(): void
    {
        $this->ship_postcode = Postcode::normalise($this->ship_postcode) ?? '';
        $this->bill_postcode = Postcode::normalise($this->bill_postcode) ?? '';
        $this->vat_number = VatNumber::normalise($this->vat_number) ?? '';
    }

    /**
     * What PlaceOrder needs, from the fields on the page.
     *
     * @return array<string, mixed>
     */
    private function details(): array
    {
        $shipping = [
            'line_one' => $this->ship_line_one,
            'line_two' => $this->ship_line_two,
            'city' => $this->ship_city,
            'county' => $this->ship_county,
            'postcode' => $this->ship_postcode,
        ];

        return [
            'payment_method' => $this->payment_method,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company_name' => $this->company_name,
            'vat_number' => $this->vat_number !== '' ? $this->vat_number : null,
            'po_number' => $this->po_number !== '' ? $this->po_number : null,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'site_type' => $this->site_type,
            'shipping' => $shipping,
            'billing' => $this->billing_same ? $shipping : [
                'line_one' => $this->bill_line_one,
                'line_two' => $this->bill_line_two,
                'city' => $this->bill_city,
                'county' => $this->bill_county,
                'postcode' => $this->bill_postcode,
            ],
        ];
    }

    public function render(CustomerCart $customerCart, CartLineViews $views, CartSummary $summary, DeliveryPolicy $delivery): View
    {
        $cart = $customerCart->current();
        $hasLines = $cart !== null && $cart->lines->isNotEmpty();
        $ready = $hasLines && $this->deliveryReady();

        // Delivery shows once there is an address to deliver to; until then the stack is goods and VAT.
        if ($ready) {
            $cart = $summary->withDelivery($cart);
        }

        $lines = $hasLines ? $views->for($cart) : [];

        return view('livewire.checkout.checkout-page', [
            'lines' => $lines,
            'hasLines' => $hasLines,
            'totals' => $hasLines ? $summary->totals($cart) : null,
            'deliveryOption' => $ready ? $this->deliveryOption($delivery, $cart) : null,
            'canOrder' => $views->canCheckout($lines),
            'accountTerms' => Auth::guard('web')->user()->hasAccountTerms(),
            'cardLive' => PaymentMethods::cardLive(),
            'stripeKey' => (string) config('services.stripe.public_key'),
            'cardAmount' => $hasLines ? app(CardPayments::class)->amount($cart) : 0,
            'canPay' => $this->payment_method !== '',
            'suffix' => DisplayPrice::SUFFIX,
        ])->layout('components.layouts.checkout', ['title' => 'Checkout']);
    }

    /**
     * @return array<string, string|bool|null>
     */
    private function deliveryOption(DeliveryPolicy $delivery, Cart $cart): array
    {
        $option = $delivery->option($cart);

        return [
            'name' => $option->getName(),
            'description' => $option->getDescription(),
            'free' => $option->price->value === 0,
            'price' => (string) $option->price->formatted(trimTrailingZeros: false),
        ];
    }

    private function deliveryReady(): bool
    {
        return trim($this->ship_line_one) !== ''
            && trim($this->ship_city) !== ''
            && preg_match(Postcode::PATTERN, (string) Postcode::normalise($this->ship_postcode)) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $postcode = function (string $attribute, mixed $value, Closure $fail): void {
            if (preg_match(Postcode::PATTERN, (string) Postcode::normalise((string) $value)) !== 1) {
                $fail('Enter a valid UK postcode.');
            }
        };

        $billing = fn (array $rules): array => $this->billing_same ? ['nullable'] : $rules;

        return [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'regex:/^\+?[0-9][0-9\s()\-]{6,19}$/'],
            'company_name' => ['required', 'string', 'max:150'],
            'vat_number' => ['nullable', new VatNumber],
            'po_number' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'site_type' => ['required', Rule::in(['commercial', 'residential'])],
            'ship_line_one' => ['required', 'string', 'max:150'],
            'ship_line_two' => ['nullable', 'string', 'max:150'],
            'ship_city' => ['required', 'string', 'max:100'],
            'ship_county' => ['nullable', 'string', 'max:100'],
            'ship_postcode' => ['required', $postcode],
            'bill_line_one' => $billing(['required', 'string', 'max:150']),
            'bill_line_two' => ['nullable', 'string', 'max:150'],
            'bill_city' => $billing(['required', 'string', 'max:100']),
            'bill_county' => ['nullable', 'string', 'max:100'],
            'bill_postcode' => $billing(['required', $postcode]),
            'payment_method' => ['required', Rule::in(PaymentMethods::available(Auth::guard('web')->user()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'full_name' => 'full name',
            'phone' => 'phone number',
            'company_name' => 'company name',
            'vat_number' => 'VAT number',
            'po_number' => 'PO number',
            'ship_line_one' => 'address line 1',
            'ship_city' => 'town or city',
            'ship_postcode' => 'postcode',
            'bill_line_one' => 'billing address line 1',
            'bill_city' => 'billing town or city',
            'bill_postcode' => 'billing postcode',
            'payment_method' => 'payment method',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'phone.regex' => 'Enter a phone number the courier can call.',
            'payment_method.required' => 'There is no payment method available on your account yet.',
            'payment_method.in' => 'That payment method is not available on your account.',
        ];
    }
}
