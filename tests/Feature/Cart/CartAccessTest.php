<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\CartCount;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;

// Guests have no prices and no cart (docs/accounts.md). These tests sign nobody in.

it('sends a guest who opens the cart to log in', function (): void {
    $this->get(route('cart'))->assertRedirect(route('login'));
});

it('opens the cart for a signed-in customer, out of search engines, with the empty state', function (): void {
    $this->actingAs(User::factory()->create(), 'web');

    $this->get(route('cart'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertSee('<h1', false)
        ->assertSee('Your cart is empty.')
        ->assertSee('data-cart-empty', false)
        ->assertDontSee('data-checkout-link', false);
});

it('does not open the cart to a staff member signed in to Lunar', function (): void {
    $this->actingAs(Lunar\Admin\Models\Staff::factory()->create(), 'staff');

    $this->get(route('cart'))->assertRedirect(route('login'));
});

it('counts nothing for a guest, even when a cart id is in the session', function (): void {
    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
    ]);

    $this->withSession([config('lunar.cart_session.session_key') => $cart->id]);

    expect(CartCount::get())->toBe(0);
});
