<?php

declare(strict_types=1);

use App\Domain\Checkout\PaymentMethods;
use App\Livewire\Checkout\CheckoutPage;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Lunar\Facades\CartSession;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use Tests\Support\CatalogueFactory;

// Payment options per customer (docs/cart.md): every customer sees card, which is not built yet
// (Stripe), and only a customer staff have switched 30 day account terms on for can choose
// "30 day account" and place an order.

function termsCart(User $owner): void
{
    $variant = CatalogueFactory::product('Terms Boot', [['stock' => 50]], 'published', 5000, 'standard')->variants->sole();

    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $owner->id,
    ]);
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);

    CartSession::use($cart);
}

function termsDetails(Testable $page): Testable
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

it('has account terms off for a new customer', function (): void {
    $user = User::factory()->create();

    expect($user->hasAccountTerms())->toBeFalse()
        ->and(PaymentMethods::available($user))->toBe([])
        ->and(PaymentMethods::default($user))->toBe('');
});

it('offers 30 day account only to a customer who has it', function (): void {
    $withTerms = User::factory()->withAccountTerms()->create();

    expect(PaymentMethods::available($withTerms))->toBe(['account-30-day'])
        ->and(PaymentMethods::canUse($withTerms, 'account-30-day'))->toBeTrue()
        ->and(PaymentMethods::canUse($withTerms, 'card'))->toBeFalse();
});

it('shows a card-only customer the card option, without 30 day account, and cannot place an order', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    termsCart($user);

    Livewire::test(CheckoutPage::class)
        ->assertSeeHtml('data-payment-option="card"')
        ->assertDontSeeHtml('data-payment-option="account-30-day"')
        ->assertSeeHtml('data-no-payment-method')
        ->assertSet('payment_method', '');
});

it('refuses to place an order for a customer without account terms, even if the browser asks for it', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    termsCart($user);

    termsDetails(Livewire::test(CheckoutPage::class))
        ->set('payment_method', 'account-30-day')
        ->call('placeOrder')
        ->assertHasErrors(['payment_method']);

    expect(Order::query()->whereNotNull('placed_at')->count())->toBe(0);
});

it('places the order on account for a customer who has terms', function (): void {
    $user = User::factory()->withAccountTerms()->create();
    $this->actingAs($user, 'web');
    termsCart($user);

    termsDetails(Livewire::test(CheckoutPage::class))
        ->call('placeOrder')
        ->assertHasNoErrors();

    $order = Order::query()->sole();

    expect($order->user_id)->toBe($user->id)
        ->and($order->placed_at)->not->toBeNull()
        ->and($order->meta['payment_method'])->toBe('account-30-day');
});

it('stops a customer whose terms were switched off after the page loaded', function (): void {
    $user = User::factory()->withAccountTerms()->create();
    $this->actingAs($user, 'web');
    termsCart($user);

    $page = termsDetails(Livewire::test(CheckoutPage::class));

    $user->forceFill(['account_terms' => false])->save();

    $page->call('placeOrder')->assertHasErrors(['payment_method']);

    expect(Order::query()->whereNotNull('placed_at')->count())->toBe(0);
});
