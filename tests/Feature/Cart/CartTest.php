<?php

declare(strict_types=1);

use App\Livewire\Cart\CartPage;
use App\Livewire\Cart\MiniCart;
use App\Livewire\Catalogue\ProductPage;
use App\Models\User;
use App\Support\CartCount;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Lunar\Facades\CartSession;
use Lunar\Models\Cart;
use Lunar\Models\CartLine;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\ProductVariant;
use Tests\Support\CatalogueFactory;

const CART_SESSION_KEY = 'lunar_cart';

beforeEach(function (): void {
    config()->set('store.noindex', true);

    $this->customer = User::factory()->create(['email' => 'buyer@builder.example', 'password' => 'a-good-password-1']);
    $this->actingAs($this->customer, 'web');
});

/** One variant, no options, at £50.00 ex VAT. */
function cartBoot(int $stock = 4, string $name = 'Cart Boot'): ProductVariant
{
    return CatalogueFactory::product($name, [['stock' => $stock]])->variants->sole();
}

function slugOf(ProductVariant $variant): string
{
    return $variant->product->defaultUrl->slug;
}

/** A cart built straight in the database, for tests that do not go through the product page. */
function cartWith(User $owner, ProductVariant $variant, int $quantity): Cart
{
    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $owner->id,
    ]);

    $cart->lines()->create([
        'purchasable_type' => $variant->getMorphClass(),
        'purchasable_id' => $variant->id,
        'quantity' => $quantity,
        'meta' => [],
    ]);

    return $cart;
}

/** Another line on an existing cart, built straight in the database. */
function addLine(Cart $cart, ProductVariant $variant, int $quantity = 1): void
{
    $cart->lines()->create([
        'purchasable_type' => $variant->getMorphClass(),
        'purchasable_id' => $variant->id,
        'quantity' => $quantity,
        'meta' => [],
    ]);
}

function addFromProductPage(ProductVariant $variant, string $quantity): Testable
{
    return Livewire::test(ProductPage::class, ['slug' => slugOf($variant)])
        ->set('quantity', $quantity)
        ->call('addToCart');
}

// --- Adding ----------------------------------------------------------------

it('puts the chosen variant and quantity in the customer\'s cart', function (): void {
    $variant = cartBoot();

    addFromProductPage($variant, '2')
        ->assertSet('added', true)
        ->assertSet('cartError', null)
        ->assertSee('2 added to your cart.')
        ->assertSee('View cart')
        ->assertDispatched('cart-updated', count: 2);

    $cart = Cart::query()->sole();
    $line = $cart->lines()->sole();

    expect($cart->user_id)->toBe($this->customer->id)
        ->and($line->purchasable_id)->toBe($variant->id)
        ->and($line->quantity)->toBe(2)
        ->and(CartCount::get())->toBe(2);
});

it('adds to the existing line when the same variant is added again', function (): void {
    $variant = cartBoot();

    addFromProductPage($variant, '1');
    addFromProductPage($variant, '2')->assertSet('added', true);

    expect(Cart::query()->count())->toBe(1)
        ->and(CartLine::query()->count())->toBe(1)
        ->and(CartLine::query()->sole()->quantity)->toBe(3)
        ->and(CartCount::get())->toBe(3);
});

it('refuses an add that, with what is already in the cart, is more than the stock', function (): void {
    $variant = cartBoot(stock: 4);

    addFromProductPage($variant, '3');

    addFromProductPage($variant, '2')
        ->assertSet('added', false)
        ->assertSet('cartError', 'Only 1 more available, and you have 3 in your cart.')
        ->assertSee('Only 1 more available, and you have 3 in your cart.')
        ->assertNotDispatched('cart-updated');

    expect(CartLine::query()->sole()->quantity)->toBe(3);
});

it('says so when everything in stock is already in the cart', function (): void {
    $variant = cartBoot(stock: 2);

    addFromProductPage($variant, '2');

    addFromProductPage($variant, '1')->assertSet('cartError', 'You already have all 2 available in your cart.');
});

it('refuses to add a product that was withdrawn after the page opened', function (): void {
    $variant = cartBoot();

    $page = Livewire::test(ProductPage::class, ['slug' => slugOf($variant)])->set('quantity', '1');

    $variant->product->update(['status' => 'draft']);

    $page->call('addToCart')->assertSet('added', false)->assertSet('cartError', 'This item is no longer available.');

    expect(CartLine::query()->count())->toBe(0);
});

it('keeps different variants of a product on separate lines', function (): void {
    CatalogueFactory::product('Sized Boot', [
        ['size' => '7', 'stock' => 5],
        ['size' => '8', 'stock' => 5],
    ]);

    Livewire::test(ProductPage::class, ['slug' => 'test-brand-sized-boot'])
        ->call('choose', 'shoe_size', '7')->set('quantity', '1')->call('addToCart')
        ->call('choose', 'shoe_size', '8')->call('addToCart');

    expect(CartLine::query()->count())->toBe(2)
        ->and(CartCount::get())->toBe(2);
});

// --- The cart page -----------------------------------------------------------

it('lists the lines with their unit price, line total and subtotal, all ex VAT', function (): void {
    $variant = cartBoot();
    $cart = cartWith($this->customer, $variant, 2);

    $html = $this->withSession([CART_SESSION_KEY => $cart->id])->get(route('cart'))->assertOk()->getContent();

    expect($html)
        ->toContain('Cart Boot')
        ->toContain('data-cart-line')
        ->toMatch('#data-line-unit>\s*£50\.00#')
        ->toMatch('#data-line-total>\s*£100\.00#')
        ->toMatch('#data-cart-subtotal>\s*£100\.00#')
        ->toContain('ex VAT')
        // The cart summary lists the lines again: price, quantity and line total, without VAT.
        ->toContain('data-cart-summary-lines')
        ->toMatch('#data-cart-summary-line-total>\s*£100\.00#')
        ->toContain('£50.00 each')
        // No VAT and no delivery on the cart: they are worked out at checkout.
        ->toContain('VAT and delivery are worked out at checkout.')
        ->not->toContain('data-line-vat')
        ->not->toContain('data-zero-rated')
        ->not->toContain('data-cart-vat')
        ->not->toContain('data-cart-delivery')
        ->not->toContain('data-vat-breakdown')
        ->not->toContain('Net Total')
        ->not->toContain('incl. VAT')
        // Every line says its price, quantity and line total in words.
        ->toContain('>Price<')
        ->toContain('Qty<span')
        ->toContain('Line total')
        ->toContain('href="'.route('product', slugOf($variant)).'"');
});

it('links Proceed to checkout when every line can be bought', function (): void {
    $cart = cartWith($this->customer, cartBoot(), 1);

    $this->withSession([CART_SESSION_KEY => $cart->id])->get(route('cart'))
        ->assertSee('data-checkout-link', false)
        ->assertSee('href="'.route('checkout').'"', false)
        ->assertDontSee('data-checkout-disabled', false);
});

it('changes a line\'s quantity and tells the header', function (): void {
    $variant = cartBoot(stock: 10);
    $cart = cartWith($this->customer, $variant, 2);
    $line = $cart->lines()->sole();
    CartSession::use($cart);

    Livewire::test(CartPage::class)
        ->assertSet("quantities.{$line->id}", '2')
        ->set("quantities.{$line->id}", '5')
        ->assertSet("quantities.{$line->id}", '5')
        ->assertDispatched('cart-updated', count: 5)
        ->assertSeeHtml('£250.00');

    expect($line->fresh()->quantity)->toBe(5);
});

it('refuses a quantity above the stock and shows what the cart holds', function (): void {
    $cart = cartWith($this->customer, cartBoot(stock: 3), 2);
    $line = $cart->lines()->sole();
    CartSession::use($cart);

    Livewire::test(CartPage::class)
        ->set("quantities.{$line->id}", '9')
        ->assertSet("quantities.{$line->id}", '2')
        ->assertSee('Only 3 available.')
        ->assertSeeHtml('aria-invalid="true"');

    expect($line->fresh()->quantity)->toBe(2);
});

it('rejects a quantity that is not a whole number of 1 or more', function (string $typed): void {
    $cart = cartWith($this->customer, cartBoot(), 2);
    $line = $cart->lines()->sole();
    CartSession::use($cart);

    Livewire::test(CartPage::class)
        ->set("quantities.{$line->id}", $typed)
        ->assertSee('Enter a whole number, 1 or more.');

    expect($line->fresh()->quantity)->toBe(2);
})->with(['0', '-1', '1.5', 'abc', '', '9999999']);

it('removes a line, says so, and shows the empty cart', function (): void {
    $cart = cartWith($this->customer, cartBoot(), 2);
    $line = $cart->lines()->sole();
    CartSession::use($cart);

    Livewire::test(CartPage::class)
        ->call('remove', $line->id)
        ->assertSee('The item was removed from your cart.')
        ->assertSee('Your cart is empty.')
        ->assertDispatched('cart-updated', count: 0);

    expect(CartLine::query()->count())->toBe(0);
});

it('never changes or removes another customer\'s cart line', function (): void {
    $other = User::factory()->create();
    $theirs = cartWith($other, cartBoot(name: 'Their Boot'), 2)->lines()->sole();

    $mine = cartWith($this->customer, cartBoot(name: 'My Boot'), 1);
    CartSession::use($mine);

    Livewire::test(CartPage::class)
        ->call('remove', $theirs->id)
        ->assertSee('That item is no longer in your cart.')
        ->set("quantities.{$theirs->id}", '4')
        ->assertSee('That item is no longer in your cart.');

    expect($theirs->fresh())->not->toBeNull()
        ->and($theirs->fresh()->quantity)->toBe(2)
        ->and($mine->lines()->sole()->quantity)->toBe(1);
});

it('flags a line whose stock fell below its quantity and holds back checkout', function (): void {
    $variant = cartBoot(stock: 5);
    $cart = cartWith($this->customer, $variant, 4);

    $variant->update(['stock' => 2]);

    $this->withSession([CART_SESSION_KEY => $cart->id])->get(route('cart'))
        ->assertSee('Only 2 in stock. Reduce the quantity to continue.')
        ->assertSee('data-checkout-disabled', false)
        ->assertDontSee('data-checkout-link', false);
});

it('flags a line that is out of stock or no longer on sale', function (string $change, string $message): void {
    $variant = cartBoot();
    $cart = cartWith($this->customer, $variant, 1);

    match ($change) {
        'out of stock' => $variant->update(['stock' => 0]),
        'withdrawn' => $variant->product->update(['status' => 'draft']),
    };

    $this->withSession([CART_SESSION_KEY => $cart->id])->get(route('cart'))
        ->assertSee($message)
        ->assertSee('data-checkout-disabled', false);
})->with([
    ['out of stock', 'This item is out of stock. Remove it to continue.'],
    ['withdrawn', 'This item is no longer available. Remove it to continue.'],
]);

// --- The header badge -------------------------------------------------------

it('shows the number of items in the header badge', function (): void {
    $cart = cartWith($this->customer, cartBoot(), 3);

    $html = $this->withSession([CART_SESSION_KEY => $cart->id])->get(route('shop'))->getContent();

    preg_match('#<a\s+href="'.preg_quote(route('cart'), '#').'"[^>]*data-cart-link.*?</a>#s', $html, $link);

    expect($link)->not->toBeEmpty()
        ->and($link[0])->toContain('aria-label="Cart, 3 items"')
        ->and($link[0])->toMatch('#data-cart-count[^>]*>3<#')
        // The product and cart pages update it without a reload.
        ->and($link[0])->toContain('x-on:cart-updated.window');
});

it('says "1 item", not "1 items"', function (): void {
    $cart = cartWith($this->customer, cartBoot(), 1);

    $this->withSession([CART_SESSION_KEY => $cart->id])->get(route('shop'))
        ->assertSee('aria-label="Cart, 1 item"', false);
});

it('does not count a deleted cart', function (): void {
    $cart = cartWith($this->customer, cartBoot(), 3);
    $cart->delete();

    $this->withSession([CART_SESSION_KEY => $cart->id]);

    expect(CartCount::get())->toBe(0);
});

// --- Signing out and in ------------------------------------------------------

it('keeps the cart when the customer logs out, clears it from the session, and brings it back at the next login', function (): void {
    $cart = cartWith($this->customer, cartBoot(), 2);

    $this->withSession([CART_SESSION_KEY => $cart->id])
        ->post(route('logout'))
        ->assertRedirect(route('home'))
        ->assertSessionMissing(CART_SESSION_KEY);

    expect(Cart::query()->find($cart->id))->not->toBeNull();

    $this->post(route('login'), ['email' => 'buyer@builder.example', 'password' => 'a-good-password-1'])
        ->assertSessionHas(CART_SESSION_KEY, $cart->id);
});

it('does not show the next visitor on the same browser the cart of the customer who logged out', function (): void {
    $cart = cartWith($this->customer, cartBoot(), 2);

    $this->withSession([CART_SESSION_KEY => $cart->id])->post(route('logout'));

    $this->actingAs(User::factory()->create(), 'web');

    expect(CartCount::get())->toBe(0);
});

it('does not touch the cart code for a guest browsing the shop', function (): void {
    auth('web')->logout();

    $this->get(route('shop'))->assertOk()->assertDontSee('data-cart-link', false);

    expect(Cart::query()->count())->toBe(0);
});

// --- The mini cart (header hover) ---------------------------------------------------

it('puts the mini cart in the header for a customer, loading nothing until it is opened', function (): void {
    $cart = cartWith($this->customer, cartBoot(), 2);

    $html = $this->withSession([CART_SESSION_KEY => $cart->id])->get(route('shop'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-mini-cart-panel')
        ->toContain('data-mini-cart-loading')
        ->toContain("Livewire.dispatch('mini-cart-open')")
        // No cart lines are rendered into every page.
        ->not->toContain('data-mini-cart-line');
});

it('has no mini cart for a guest, and none on the cart page itself', function (): void {
    auth('web')->logout();
    $this->get(route('shop'))->assertDontSee('data-mini-cart', false);

    $this->actingAs(User::factory()->create(), 'web');
    $this->get(route('cart'))->assertOk()->assertDontSee('data-mini-cart-panel', false);
});

it('shows the lines, the subtotal and the buttons once opened', function (): void {
    $variant = cartBoot();
    $cart = cartWith($this->customer, $variant, 2);
    CartSession::use($cart);

    Livewire::test(MiniCart::class)
        ->assertSee('Loading your cart...')
        ->dispatch('mini-cart-open')
        ->assertSet('loaded', true)
        ->assertSee('Cart Boot')
        ->assertSee('2 x £50.00')
        ->assertSeeHtml('£100.00')
        ->assertSee('ex VAT')
        ->assertSeeHtml('href="'.route('cart').'"')
        ->assertSeeHtml('data-mini-cart-checkout')
        ->assertSeeHtml('href="'.route('checkout').'"')
        ->assertDontSeeHtml('data-mini-cart-checkout-disabled');
});

it('shows no VAT and no delivery in the mini cart: those are worked out at checkout', function (): void {
    $variant = CatalogueFactory::product('Kids Pricey', [['stock' => 5000]], 'published', 5000, 'zero-childrens')->variants->sole();
    CartSession::use(cartWith($this->customer, $variant, 2));

    $html = Livewire::test(MiniCart::class)->dispatch('mini-cart-open')->html();

    expect($html)->toContain('2 x £50.00')
        ->toMatch('#data-mini-cart-subtotal>\s*£100\.00#')
        ->toContain('VAT and delivery are worked out at checkout.')
        ->not->toContain('data-mini-cart-vat')
        ->not->toContain('data-mini-cart-delivery')
        ->not->toContain('data-mini-cart-line-vat')
        ->not->toContain('Zero-rated')
        ->not->toContain('Delivery')
        ->not->toContain('incl. VAT');
});

it('says the cart is empty when it is', function (): void {
    Livewire::test(MiniCart::class)->dispatch('mini-cart-open')->assertSee('Your cart is empty.');
});

it('refreshes when the cart changes', function (): void {
    $cart = cartWith($this->customer, cartBoot(), 1);
    $line = $cart->lines()->sole();
    CartSession::use($cart);

    $mini = Livewire::test(MiniCart::class)->dispatch('mini-cart-open')->assertSee('1 x £50.00');

    $line->update(['quantity' => 3]);

    // The session manager keeps the cart it loaded for the whole process; a real change arrives in a new request.
    CartSession::forget(false);
    CartSession::use($cart->fresh());

    $mini->dispatch('cart-updated', count: 3)->assertSee('3 x £50.00');
});

it('shows four lines and says how many more there are', function (): void {
    $cart = cartWith($this->customer, cartBoot(name: 'Boot 1'), 1);

    foreach (range(2, 6) as $n) {
        addLine($cart, cartBoot(name: "Boot {$n}"));
    }

    CartSession::use($cart);

    Livewire::test(MiniCart::class)
        ->dispatch('mini-cart-open')
        ->assertSee('and 2 more items in your cart')
        ->assertSee('Boot 1')
        ->assertDontSee('Boot 6');
});

it('holds back the mini cart checkout button when any line has a problem, even one not shown', function (): void {
    $cart = cartWith($this->customer, cartBoot(name: 'Boot 1'), 1);

    foreach (range(2, 5) as $n) {
        addLine($cart, cartBoot(stock: $n === 5 ? 0 : 4, name: "Boot {$n}"));
    }

    CartSession::use($cart);

    Livewire::test(MiniCart::class)
        ->dispatch('mini-cart-open')
        ->assertSeeHtml('data-mini-cart-checkout-disabled')
        ->assertDontSeeHtml('href="'.route('checkout').'"');
});
