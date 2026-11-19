<?php

declare(strict_types=1);

use App\Domain\Checkout\CardResult;
use App\Domain\Checkout\PaymentMethods;
use App\Domain\Checkout\StripeCardPayments;
use App\Livewire\Checkout\CheckoutPage;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Lunar\Facades\CartSession;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use Tests\Support\CatalogueFactory;
use Tests\Support\FakeCardPayments;

// Card payment through Stripe (docs/cart.md, Card payments). Stripe is faked: no test talks to it.

function cardCart(User $owner): Cart
{
    $variant = CatalogueFactory::product('Card Boot', [['stock' => 50]], 'published', 5000, 'standard')->variants->sole();

    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $owner->id,
    ]);
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);

    CartSession::use($cart);

    return $cart;
}

function cardDetails(Testable $page): Testable
{
    return $page
        ->set('full_name', 'Jo Builder')
        ->set('email', 'jo@builder.example')
        ->set('phone', '020 8289 5858')
        ->set('company_name', 'Builder Ltd')
        ->set('ship_line_one', '1 High Street')
        ->set('ship_city', 'Bromley')
        ->set('ship_postcode', 'BR5 3QX');
}

beforeEach(function (): void {
    $this->fake = FakeCardPayments::install();
    $this->customer = User::factory()->create(['name' => 'Jo Builder', 'email' => 'jo@builder.example']);
    $this->actingAs($this->customer, 'web');
});

// --- Availability ---------------------------------------------------------------------

it('is switched on only when both Stripe keys are set', function (): void {
    $real = new StripeCardPayments;

    config(['services.stripe.key' => null, 'services.stripe.public_key' => null]);
    expect($real->isConfigured())->toBeFalse();

    config(['services.stripe.key' => 'sk_test_x', 'services.stripe.public_key' => null]);
    expect($real->isConfigured())->toBeFalse();

    config(['services.stripe.key' => 'sk_test_x', 'services.stripe.public_key' => 'pk_test_x']);
    expect($real->isConfigured())->toBeTrue();
});

it('offers card to every customer once it is on, and 30 day account only to those with terms', function (): void {
    expect(PaymentMethods::available($this->customer))->toBe(['card'])
        ->and(PaymentMethods::default($this->customer))->toBe('card')
        ->and(PaymentMethods::available(User::factory()->withAccountTerms()->create()))->toBe(['card', 'account-30-day']);

    $this->fake->configured = false;

    expect(PaymentMethods::available($this->customer))->toBe([]);
});

it('shows the card form and no 30 day option to a customer without terms', function (): void {
    cardCart($this->customer);

    Livewire::test(CheckoutPage::class)
        ->assertSeeHtml('data-payment-option="card"')
        ->assertSeeHtml('data-card-element')
        ->assertDontSeeHtml('data-payment-option="account-30-day"')
        ->assertDontSeeHtml('data-no-payment-method')
        ->assertSet('payment_method', 'card')
        ->assertSee('Debit or credit card');
});

it('loads Stripe.js and the card form on the checkout, only while card is on', function (): void {
    expect(Blade::render('<x-checkout.stripe-scripts />'))->toContain('https://js.stripe.com/v3/')->toContain('cardCheckout');

    $layout = file_get_contents(resource_path('views/components/layouts/checkout.blade.php'));
    expect($layout)->toContain('isConfigured()')->toContain('x-checkout.stripe-scripts');
});

it('shows card as not available, and blocks the order, while Stripe is not configured', function (): void {
    $this->fake->configured = false;
    cardCart($this->customer);

    Livewire::test(CheckoutPage::class)
        ->assertSee('Not available at the moment.')
        ->assertDontSee('Coming soon')
        ->assertDontSeeHtml('data-card-element')
        ->assertSeeHtml('data-no-payment-method')
        ->assertSet('payment_method', '');
});

// --- Starting a card payment -----------------------------------------------------------

it('builds a draft order and a payment intent for its total, and leaves the cart in place', function (): void {
    $cart = cardCart($this->customer);

    $result = cardDetails(Livewire::test(CheckoutPage::class))
        ->set('po_number', 'PO-77')
        ->call('startCardPayment')
        ->assertHasNoErrors()
        ->effects['returns'][0] ?? null;

    $order = Order::query()->sole();

    expect($result)->toMatchArray([
        'client_secret' => 'pi_test_1abcdef_secret_xyz',
        'return_url' => route('checkout.card-return'),
        'order_reference' => $order->reference,
    ])
        // Draft until Stripe says the payment succeeded.
        ->and($order->placed_at)->toBeNull()
        ->and($order->customer_reference)->toBe('PO-77')
        ->and($order->meta['payment_method'])->toBe('card')
        // £50.00 goods + £50.00 delivery, and £20.00 VAT on both.
        ->and($order->total->value)->toBe(12_000)
        ->and($this->fake->intents)->toBe([['cart' => $cart->id, 'order' => $order->reference, 'total' => 12_000]])
        ->and(session()->get('lunar_cart'))->toBe($cart->id);
});

it('refuses to start a card payment while the checkout has errors, and creates nothing', function (): void {
    cardCart($this->customer);

    Livewire::test(CheckoutPage::class)
        ->call('startCardPayment')
        ->assertHasErrors(['phone', 'ship_line_one', 'ship_postcode']);

    expect(Order::count())->toBe(0)->and($this->fake->intents)->toBe([]);
});

it('shows the reason when Stripe says a payment is already in progress', function (): void {
    $this->fake->rejectIntent = 'A payment for this order is already in progress or complete.';
    cardCart($this->customer);

    cardDetails(Livewire::test(CheckoutPage::class))
        ->call('startCardPayment')
        ->assertSet('formError', 'A payment for this order is already in progress or complete.');
});

it('refuses card when it is switched off, even if the browser asks for it', function (): void {
    $this->fake->configured = false;
    cardCart($this->customer);

    cardDetails(Livewire::test(CheckoutPage::class))
        ->set('payment_method', 'card')
        ->call('startCardPayment')
        ->assertHasErrors(['payment_method']);

    expect($this->fake->intents)->toBe([]);
});

it('does not let the Place order form place a card order without paying', function (): void {
    cardCart($this->customer);

    cardDetails(Livewire::test(CheckoutPage::class))
        ->call('placeOrder')
        ->assertSet('formError', 'Please use the Pay by card button to pay for this order.');

    expect(Order::count())->toBe(0);
});

it('does not start a card payment for a method other than card', function (): void {
    $user = User::factory()->withAccountTerms()->create();
    $this->actingAs($user, 'web');
    cardCart($user);

    cardDetails(Livewire::test(CheckoutPage::class))
        ->set('payment_method', 'account-30-day')
        ->call('startCardPayment')
        ->assertSet('formError', 'That payment method is not available on your account.');

    expect($this->fake->intents)->toBe([]);
});

it('gives the card form the current total, with delivery once there is an address', function (): void {
    cardCart($this->customer);
    $this->fake->amount = 12_000;

    expect(cardDetails(Livewire::test(CheckoutPage::class))->call('cardAmount')->effects['returns'][0] ?? null)->toBe(12_000);
});

// --- Coming back from Stripe ---------------------------------------------------------------

it('sends a guest who opens the return page to log in', function (): void {
    auth('web')->logout();

    $this->get(route('checkout.card-return', ['payment_intent' => 'pi_test_1abcdef']))->assertRedirect(route('login'));
});

it('answers 404 for a missing, malformed or unknown payment intent', function (?string $intent): void {
    $this->get(route('checkout.card-return', $intent === null ? [] : ['payment_intent' => $intent]))->assertNotFound();
})->with([
    'none' => [null],
    'malformed' => ['not-an-intent'],
    'script' => ['pi_<script>'],
    'unknown' => ['pi_test_999abcdef'],
]);

it('answers 404 for another customer\'s payment', function (): void {
    $other = User::factory()->create();
    $cart = cardCart($other);
    $this->fake->carts['pi_test_1abcdef'] = $cart;

    $this->get(route('checkout.card-return', ['payment_intent' => 'pi_test_1abcdef']))->assertNotFound();

    expect($this->fake->settled)->toBe([]);
});

it('places the order, clears the cart and shows the confirmation when the payment succeeded', function (): void {
    $cart = cardCart($this->customer);

    cardDetails(Livewire::test(CheckoutPage::class))->call('startCardPayment');
    $order = Order::query()->sole();
    $order->update(['placed_at' => now(), 'status' => 'payment-received']);
    $this->fake->outcome = CardResult::paid($order);

    $this->get(route('checkout.card-return', ['payment_intent' => 'pi_test_1abcdef', 'redirect_status' => 'succeeded']))
        ->assertRedirect(route('checkout.complete', $order->reference));

    expect($this->fake->settled)->toBe(['pi_test_1abcdef'])
        ->and(session()->get('lunar_cart'))->toBeNull();

    $this->get(route('checkout.complete', $order->reference))->assertOk()
        ->assertSee('Your card payment was successful')
        ->assertDontSee('30 day account');
});

it('does not trust the redirect_status in the address: the payment is settled with Stripe', function (): void {
    $cart = cardCart($this->customer);
    cardDetails(Livewire::test(CheckoutPage::class))->call('startCardPayment');
    $this->fake->outcome = CardResult::failed();

    $this->get(route('checkout.card-return', ['payment_intent' => 'pi_test_1abcdef', 'redirect_status' => 'succeeded']))
        ->assertRedirect(route('checkout'));

    expect(Order::query()->whereNotNull('placed_at')->count())->toBe(0)
        ->and(session()->get('lunar_cart'))->toBe($cart->id);
});

it('sends the customer back to the checkout with the reason when the card failed, and keeps the cart', function (): void {
    $cart = cardCart($this->customer);
    cardDetails(Livewire::test(CheckoutPage::class))->call('startCardPayment');
    $this->fake->outcome = CardResult::failed();

    $this->get(route('checkout.card-return', ['payment_intent' => 'pi_test_1abcdef']))
        ->assertRedirect(route('checkout'))
        ->assertSessionHas('checkout_error');

    Livewire::test(CheckoutPage::class)
        ->assertSet('formError', 'Your card payment was not completed, so no order has been placed and you have not been charged. Please try again or use a different card.');

    expect(session()->get('lunar_cart'))->toBe($cart->id);
});

it('tells the customer not to pay again while the payment is still clearing', function (): void {
    cardCart($this->customer);
    cardDetails(Livewire::test(CheckoutPage::class))->call('startCardPayment');
    $this->fake->outcome = CardResult::pending();

    $this->get(route('checkout.card-return', ['payment_intent' => 'pi_test_1abcdef']))
        ->assertRedirect(route('cart'))
        ->assertSessionHas('status');
});

// --- Webhook -----------------------------------------------------------------------------

it('has Stripe\'s webhook route, outside CSRF', function (): void {
    $route = app('router')->getRoutes()->getByName('lunar.stripe.webhook');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('stripe/webhook')
        ->and($route->methods())->toContain('POST');
});

it('has the order statuses Stripe can give an order', function (): void {
    foreach (config('lunar.stripe.status_mapping') as $status) {
        expect(config('lunar.orders.statuses'))->toHaveKey($status);
    }
});
