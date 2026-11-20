<?php

declare(strict_types=1);

use App\Domain\Accounts\AddressBook;
use App\Domain\Orders\Invoice;
use App\Domain\Orders\OpenInvoices;
use App\Livewire\Checkout\CheckoutPage;
use App\Models\User;
use Livewire\Livewire;
use Lunar\Facades\CartSession;
use Lunar\Models\Address;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;
use Tests\Support\CatalogueFactory;

// The member extras (docs/accounts.md, Member area): buy again, the address book, payment terms and the
// delivery and returns links.

/**
 * Places a real order on 30 day terms through the checkout, with $products as [name => quantity].
 *
 * @param  array<string, int>  $products
 */
function extrasOrder(User $user, array $products = ['Extras Boot' => 1]): Order
{
    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $user->id,
    ]);

    foreach ($products as $name => $quantity) {
        $variant = CatalogueFactory::product($name, [['stock' => 50]], 'published', 5000, 'standard')->variants->sole();
        $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => $quantity, 'meta' => []]);
    }

    CartSession::use($cart);

    Livewire::actingAs($user, 'web')->test(CheckoutPage::class)
        ->set('full_name', 'Jo Builder')
        ->set('email', 'jo@builder.example')
        ->set('phone', '020 8289 5858')
        ->set('company_name', 'Builder Ltd')
        ->set('ship_line_one', '1 High Street')
        ->set('ship_city', 'Bromley')
        ->set('ship_postcode', 'BR5 3QX')
        ->call('placeOrder')
        ->assertHasNoErrors();

    CartSession::forget(false);

    return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
}

/** The cart the request left in the session. */
function sessionCart(): ?Cart
{
    $id = session()->get('lunar_cart');

    return $id ? Cart::query()->with('lines')->find($id) : null;
}

/** @return array<string, string> */
function addressInput(array $overrides = []): array
{
    return array_merge([
        'label' => 'Depot',
        'first_name' => 'Sam',
        'last_name' => 'Site',
        'company_name' => 'Builder Ltd',
        'line_one' => '9 Depot Road',
        'line_two' => '',
        'city' => 'Croydon',
        'county' => 'Surrey',
        'postcode' => 'cr0 1xx',
        'phone' => '020 8289 5858',
        'delivery_instructions' => 'Use the side gate',
    ], $overrides);
}

beforeEach(function (): void {
    config()->set('store.noindex', true);
    $this->member = User::factory()->withAccountTerms()->create(['name' => 'Jo Builder', 'email' => 'jo@builder.example']);
    $this->actingAs($this->member, 'web');
});

// --- Buy again ------------------------------------------------------------------------------

it('puts the goods from a past order into the cart in the same quantities, and goes to the cart', function (): void {
    $order = extrasOrder($this->member, ['Boot A' => 2, 'Boot B' => 1]);

    $this->post(route('account.orders.reorder', $order->reference))
        ->assertRedirect(route('cart'))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Added 3 items from order '.$order->reference));

    $cart = sessionCart();

    // The goods only: the old delivery line is never copied.
    expect($cart->lines)->toHaveCount(2)
        ->and($cart->lines->sum('quantity'))->toBe(3);
});

it('adds on top of what is already in the cart', function (): void {
    $order = extrasOrder($this->member, ['Boot A' => 2]);
    $this->post(route('account.orders.reorder', $order->reference));
    $this->post(route('account.orders.reorder', $order->reference));

    expect(sessionCart()->lines)->toHaveCount(1)
        ->and((int) sessionCart()->lines->first()->quantity)->toBe(4);
});

it('leaves out a product that is no longer sold, adds the rest, and says why', function (): void {
    $order = extrasOrder($this->member, ['Boot A' => 1, 'Boot B' => 1]);

    $withdrawn = $order->lines->where('type', 'physical')->first()->purchasable->product;
    $withdrawn->update(['status' => 'draft']);

    $this->post(route('account.orders.reorder', $order->reference))
        ->assertRedirect(route('cart'))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Added 1 item') && str_contains($status, 'Not added:') && str_contains($status, 'no longer available'));

    expect(sessionCart()->lines)->toHaveCount(1);
});

it('stays on the order with the reasons when nothing can be added', function (): void {
    $order = extrasOrder($this->member);
    ProductVariant::query()->update(['stock' => 0]);

    $this->post(route('account.orders.reorder', $order->reference))
        ->assertRedirect(route('account.orders.show', $order->reference))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'Nothing from order') && str_contains($status, 'out of stock'));

    // Lunar makes the cart on first use, so it may exist, but it is empty.
    expect(sessionCart()?->lines ?? collect())->toBeEmpty();
});

it('only buys again from the customer\'s own placed orders', function (): void {
    $other = User::factory()->withAccountTerms()->create();
    $theirs = extrasOrder($other);
    $draft = extrasOrder($this->member);
    $draft->update(['placed_at' => null]);

    $this->actingAs($this->member, 'web');

    $this->post(route('account.orders.reorder', $theirs->reference))->assertNotFound();
    $this->post(route('account.orders.reorder', $draft->reference))->assertNotFound();
    $this->post(route('account.orders.reorder', 'NOPE-1'))->assertNotFound();
    expect(sessionCart())->toBeNull();
});

it('needs a signed-in customer and a POST', function (): void {
    $order = extrasOrder($this->member);

    $this->get(route('account.orders.reorder', $order->reference))->assertStatus(405);

    auth('web')->logout();
    $this->post(route('account.orders.reorder', $order->reference))->assertRedirect(route('login'));
});

it('offers Buy again on the orders list and the order page', function (): void {
    $order = extrasOrder($this->member);

    $this->get(route('account.orders'))->assertSee('data-reorder', false)->assertSee(route('account.orders.reorder', $order->reference), false);
    $this->get(route('account.orders.show', $order->reference))->assertSee('data-reorder', false)->assertSee('Buy again');
});

// --- Address book ----------------------------------------------------------------------------

it('sends a guest from every address page to log in', function (): void {
    auth('web')->logout();

    foreach (['account.addresses', 'account.addresses.create'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    $this->post(route('account.addresses.store'), addressInput())->assertRedirect(route('login'));
    $this->get(route('account.addresses.edit', 1))->assertRedirect(route('login'));
});

it('shows the empty state, then a saved address with its details', function (): void {
    $this->get(route('account.addresses'))->assertOk()->assertSee('data-addresses-empty', false)->assertSee('noindex', false);

    $this->post(route('account.addresses.store'), addressInput())->assertRedirect(route('account.addresses'));

    $this->get(route('account.addresses'))->assertOk()
        ->assertSee('data-address', false)
        ->assertSee('Depot')
        ->assertSee('9 Depot Road')
        ->assertSee('CR0 1XX')
        ->assertSee('Use the side gate');
});

it('saves an address on the customer, in the UK, with the postcode tidied', function (): void {
    $this->post(route('account.addresses.store'), addressInput());

    $customer = $this->member->latestCustomer();
    $address = $customer->addresses()->sole();

    expect($address->line_one)->toBe('9 Depot Road')
        ->and($address->state)->toBe('Surrey')
        ->and($address->postcode)->toBe('CR0 1XX')
        ->and($address->country_id)->toBe(Country::query()->where('iso2', 'GB')->value('id'))
        ->and($address->contact_phone)->toBe('020 8289 5858')
        ->and($address->meta['label'])->toBe('Depot')
        // The first one saved is the default for delivery.
        ->and($address->shipping_default)->toBeTrue();
});

it('gives a customer made outside registration a Lunar customer when they save their first address', function (): void {
    expect($this->member->latestCustomer())->toBeNull();

    $this->post(route('account.addresses.store'), addressInput())->assertSessionHasNoErrors();

    expect($this->member->latestCustomer())->not->toBeNull()
        ->and($this->member->latestCustomer()->addresses()->count())->toBe(1)
        ->and(Customer::query()->count())->toBe(1);
});

it('refuses an invalid address and saves nothing', function (array $overrides, string $field): void {
    $this->from(route('account.addresses.create'))->post(route('account.addresses.store'), addressInput($overrides))
        ->assertRedirect(route('account.addresses.create'))
        ->assertSessionHasErrors($field);

    expect(Address::query()->count())->toBe(0);
})->with([
    'no first name' => [['first_name' => ''], 'first_name'],
    'no last name' => [['last_name' => ''], 'last_name'],
    'no address line' => [['line_one' => ''], 'line_one'],
    'no town' => [['city' => ''], 'city'],
    'no postcode' => [['postcode' => ''], 'postcode'],
    'bad postcode' => [['postcode' => 'NOT A POSTCODE'], 'postcode'],
    'bad phone' => [['phone' => 'call me'], 'phone'],
    'label too long' => [['label' => str_repeat('a', 61)], 'label'],
]);

it('makes a later address the default only when asked, and only one is ever the default', function (): void {
    $this->post(route('account.addresses.store'), addressInput(['label' => 'First']));
    $this->post(route('account.addresses.store'), addressInput(['label' => 'Second', 'line_one' => '2 Second Road']));

    $addresses = $this->member->latestCustomer()->addresses()->orderBy('id')->get();
    expect($addresses[0]->shipping_default)->toBeTrue()->and($addresses[1]->shipping_default)->toBeFalse();

    $this->post(route('account.addresses.store'), addressInput(['label' => 'Third', 'line_one' => '3 Third Road', 'make_default' => '1']));

    $defaults = $this->member->latestCustomer()->addresses()->where('shipping_default', true)->get();
    expect($defaults)->toHaveCount(1)->and($defaults->first()->meta['label'])->toBe('Third');
});

it('changes the default, edits an address, and deletes one, promoting another when the default goes', function (): void {
    $this->post(route('account.addresses.store'), addressInput(['label' => 'First']));
    $this->post(route('account.addresses.store'), addressInput(['label' => 'Second', 'line_one' => '2 Second Road']));
    [$first, $second] = $this->member->latestCustomer()->addresses()->orderBy('id')->get()->all();

    $this->post(route('account.addresses.default', $second->id))->assertRedirect(route('account.addresses'));
    expect($second->refresh()->shipping_default)->toBeTrue()->and($first->refresh()->shipping_default)->toBeFalse();

    $this->put(route('account.addresses.update', $first->id), addressInput(['label' => 'Renamed', 'line_one' => '1 New Street']))
        ->assertRedirect(route('account.addresses'));
    expect($first->refresh()->line_one)->toBe('1 New Street')->and($first->meta['label'])->toBe('Renamed');

    // Deleting the default hands the role to the one that is left.
    $this->delete(route('account.addresses.destroy', $second->id))->assertRedirect(route('account.addresses'));
    expect(Address::query()->whereKey($second->id)->exists())->toBeFalse()
        ->and($first->refresh()->shipping_default)->toBeTrue();
});

it('shows the edit form filled in', function (): void {
    $this->post(route('account.addresses.store'), addressInput());
    $address = $this->member->latestCustomer()->addresses()->sole();

    $this->get(route('account.addresses.edit', $address->id))->assertOk()
        ->assertSee('value="9 Depot Road"', false)
        ->assertSee('value="Depot"', false);
});

it('never lets a customer see, change or delete another customer\'s address', function (): void {
    $other = User::factory()->create();
    (new AddressBook)->save($other, addressInput(['label' => 'Theirs']));
    $theirs = $other->latestCustomer()->addresses()->sole();

    $this->post(route('account.addresses.store'), addressInput(['label' => 'Mine']));

    $this->get(route('account.addresses.edit', $theirs->id))->assertNotFound();
    $this->put(route('account.addresses.update', $theirs->id), addressInput(['label' => 'Hacked']))->assertNotFound();
    $this->post(route('account.addresses.default', $theirs->id))->assertNotFound();
    $this->delete(route('account.addresses.destroy', $theirs->id))->assertNotFound();

    expect($theirs->refresh()->meta['label'])->toBe('Theirs')
        ->and($this->get(route('account.addresses'))->getContent())->not->toContain('Theirs');
});

it('stops at 25 saved addresses', function (): void {
    $book = new AddressBook;

    foreach (range(1, AddressBook::MAX) as $n) {
        $book->save($this->member, addressInput(['label' => "Address {$n}", 'line_one' => "{$n} Long Road"]));
    }

    $this->post(route('account.addresses.store'), addressInput(['label' => 'One too many']))
        ->assertRedirect(route('account.addresses'))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'up to 25'));

    expect($this->member->latestCustomer()->addresses()->count())->toBe(25);
});

// --- Address book at checkout --------------------------------------------------------------------

it('starts the checkout from the default delivery address and offers the saved ones', function (): void {
    $book = new AddressBook;
    $book->save($this->member, addressInput(['label' => 'Head office', 'line_one' => '1 Head Office Road', 'postcode' => 'SW1A 1AA']));
    $book->save($this->member, addressInput(['label' => 'Depot', 'line_one' => '9 Depot Road', 'make_default' => true]));

    $variant = CatalogueFactory::product('Checkout Boot', [['stock' => 5]], 'published', 5000)->variants->sole();
    $cart = Cart::query()->create(['currency_id' => Currency::getDefault()->id, 'channel_id' => Channel::getDefault()->id, 'user_id' => $this->member->id]);
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);
    CartSession::use($cart);

    $default = $this->member->latestCustomer()->addresses()->where('shipping_default', true)->sole();

    Livewire::test(CheckoutPage::class)
        ->assertSet('ship_line_one', '9 Depot Road')
        ->assertSet('saved_address', (string) $default->id)
        ->assertSeeHtml('data-saved-addresses')
        ->assertSee('Head office')
        ->assertSee('(default)')
        ->assertDontSeeHtml('data-save-address');
});

it('fills the delivery address from a saved one, and its own list steps back when the customer types over it', function (): void {
    $book = new AddressBook;
    $book->save($this->member, addressInput(['label' => 'Depot']));
    $other = $book->save($this->member, addressInput(['label' => 'Head office', 'line_one' => '1 Head Office Road', 'city' => 'London', 'postcode' => 'SW1A 1AA']));

    $variant = CatalogueFactory::product('Checkout Boot', [['stock' => 5]], 'published', 5000)->variants->sole();
    $cart = Cart::query()->create(['currency_id' => Currency::getDefault()->id, 'channel_id' => Channel::getDefault()->id, 'user_id' => $this->member->id]);
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);
    CartSession::use($cart);

    Livewire::test(CheckoutPage::class)
        ->set('saved_address', (string) $other->id)
        ->assertSet('ship_line_one', '1 Head Office Road')
        ->assertSet('ship_city', 'London')
        ->assertSet('ship_postcode', 'SW1A 1AA')
        ->set('ship_line_one', '77 Typed Street')
        ->assertSet('saved_address', '')
        ->assertSeeHtml('data-save-address');
});

it('ignores a saved address id that is not the customer\'s own', function (): void {
    $stranger = User::factory()->create();
    $theirs = (new AddressBook)->save($stranger, addressInput(['label' => 'Theirs', 'line_one' => '66 Secret Lane']));

    $variant = CatalogueFactory::product('Checkout Boot', [['stock' => 5]], 'published', 5000)->variants->sole();
    $cart = Cart::query()->create(['currency_id' => Currency::getDefault()->id, 'channel_id' => Channel::getDefault()->id, 'user_id' => $this->member->id]);
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);
    CartSession::use($cart);

    Livewire::test(CheckoutPage::class)
        ->set('saved_address', (string) $theirs->id)
        ->assertSet('saved_address', '')
        ->assertNotSet('ship_line_one', '66 Secret Lane');
});

it('saves the delivery address to the address book when the box is ticked, once, and never twice', function (): void {
    foreach ([1, 2] as $ignored) {
        $variant = CatalogueFactory::product('Checkout Boot '.$ignored, [['stock' => 5]], 'published', 5000)->variants->sole();
        $cart = Cart::query()->create(['currency_id' => Currency::getDefault()->id, 'channel_id' => Channel::getDefault()->id, 'user_id' => $this->member->id]);
        $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);
        CartSession::use($cart);

        Livewire::test(CheckoutPage::class)
            ->set('full_name', 'Jo Builder')
            ->set('email', 'jo@builder.example')
            ->set('phone', '020 8289 5858')
            ->set('company_name', 'Builder Ltd')
            ->set('ship_line_one', '5 New Site Road')
            ->set('ship_city', 'Bromley')
            ->set('ship_postcode', 'BR5 3QX')
            ->set('save_address', true)
            ->call('placeOrder')
            ->assertHasNoErrors();

        CartSession::forget(false);
    }

    $saved = $this->member->latestCustomer()->addresses()->get();

    expect($saved)->toHaveCount(1)
        ->and($saved->first()->line_one)->toBe('5 New Site Road')
        ->and($saved->first()->postcode)->toBe('BR5 3QX')
        ->and($saved->first()->shipping_default)->toBeTrue();
});

it('saves nothing when the box is not ticked', function (): void {
    extrasOrder($this->member);

    expect(Address::query()->count())->toBe(0);
});

// --- Payment terms --------------------------------------------------------------------------------

it('tells a customer without 30 day terms how to get them', function (): void {
    $plain = User::factory()->create();

    $this->actingAs($plain, 'web')->get(route('account'))->assertOk()
        ->assertSee('data-payment-terms', false)
        ->assertSee('data-terms-off', false)
        ->assertSee('Not enabled')
        ->assertSee('href="'.route('contact').'"', false)
        ->assertDontSee('Invoices still to pay');
});

it('shows a customer with terms that they are on, and that nothing is owed', function (): void {
    $this->get(route('account'))->assertOk()
        ->assertSee('data-terms-on', false)
        ->assertSee('Enabled')
        ->assertSee('data-no-open-invoices', false);
});

it('lists the unpaid account invoices, soonest due first, with the total and an overdue flag', function (): void {
    $recent = extrasOrder($this->member);
    $old = extrasOrder($this->member);
    $old->update(['placed_at' => now()->subDays(40)]);
    $paid = extrasOrder($this->member);
    $paid->update(['status' => 'payment-received']);

    $html = $this->get(route('account'))->assertOk()->getContent();

    expect(substr_count($html, 'data-open-invoice>'))->toBe(2)
        ->and($html)->toContain('INV-'.$old->reference)
        ->toContain('INV-'.$recent->reference)
        ->not->toContain('INV-'.$paid->reference)
        // The older invoice is due first, and is overdue; the recent one is not.
        ->and(strpos($html, 'INV-'.$old->reference))->toBeLessThan(strpos($html, 'INV-'.$recent->reference))
        ->and(substr_count($html, 'data-overdue'))->toBe(1)
        ->and($html)->toContain($old->placed_at->copy()->addDays(30)->format('j M Y'))
        // £120.00 each.
        ->toContain('Total to pay: <strong>£240.00</strong>');
});

it('keeps card orders off the open invoices, and orders them by due date', function (): void {
    $a = extrasOrder($this->member);
    $b = extrasOrder($this->member);
    $a->update(['placed_at' => now()->subDays(5)]);
    $b->update(['placed_at' => now()->subDays(20)]);

    $card = extrasOrder($this->member);
    $card->update(['meta' => array_merge((array) $card->meta, ['payment_method' => 'card'])]);

    $open = (new OpenInvoices)->for($this->member);

    expect($open->map(fn (Invoice $i): int => $i->order->id)->all())->toBe([$b->id, $a->id]);
});

it('knows an invoice is overdue only once its due day has passed', function (): void {
    $order = extrasOrder($this->member);

    $order->update(['placed_at' => now()->subDays(30)]);
    expect((new Invoice($order->refresh()))->isOverdue())->toBeFalse();

    $order->update(['placed_at' => now()->subDays(31)]);
    $invoice = new Invoice($order->refresh());

    expect($invoice->isOverdue())->toBeTrue()
        ->and($invoice->paymentStatus())->toStartWith('Overdue, was due by ');

    $order->update(['status' => 'payment-received']);
    expect((new Invoice($order->refresh()))->isOverdue())->toBeFalse();
});

it('marks an overdue invoice on the invoices list', function (): void {
    $order = extrasOrder($this->member);
    $order->update(['placed_at' => now()->subDays(45)]);

    $this->get(route('account.invoices'))->assertOk()
        ->assertSee('Overdue, was due by')
        ->assertSee('text-danger', false);
});

// --- Delivery and returns links --------------------------------------------------------------------

it('links delivery and returns from the member menu, and the address book', function (): void {
    foreach (['account', 'account.orders', 'account.addresses'] as $route) {
        $this->get(route($route))->assertOk()
            ->assertSee('data-member-link="addresses"', false)
            ->assertSee('data-member-help="delivery"', false)
            ->assertSee('data-member-help="returns"', false)
            ->assertSee('href="'.route('delivery').'"', false)
            ->assertSee('href="'.route('returns').'"', false);
    }
});

it('puts addresses, delivery and returns in the header menu', function (): void {
    $this->get(route('home'))->assertOk()
        ->assertSee('data-menu-addresses', false)
        ->assertSee('data-menu-delivery', false)
        ->assertSee('data-menu-returns', false);

    expect($this->get(route('delivery'))->assertOk()->getContent())->toContain('Delivery Information');
    expect($this->get(route('returns'))->assertOk()->getContent())->toContain('Returns and Refunds');
});
