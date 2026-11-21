<?php

declare(strict_types=1);

use App\Domain\Orders\Invoice;
use App\Domain\Orders\OrderStatus;
use App\Livewire\Checkout\CheckoutPage;
use App\Models\User;
use App\Support\Store;
use Livewire\Livewire;
use Lunar\Admin\Models\Staff;
use Lunar\Facades\CartSession;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use Tests\Support\CatalogueFactory;

// The member area (docs/accounts.md, Member area): a menu for signed-in customers with their orders,
// invoices, details and favourites. Orders and invoices are only ever the customer's own.

/** Places a real order on 30 day terms for the user, through the checkout, and returns it. */
function memberOrder(User $user, string $po = ''): Order
{
    $variant = CatalogueFactory::product('Member Boot '.Str::random(4), [['stock' => 50]], 'published', 5000, 'standard')->variants->sole();

    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $user->id,
    ]);
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);
    CartSession::use($cart);

    Livewire::actingAs($user, 'web')->test(CheckoutPage::class)
        ->set('full_name', 'Jo Builder')
        ->set('email', 'jo@builder.example')
        ->set('phone', '020 8289 5858')
        ->set('company_name', 'Builder Ltd')
        ->set('vat_number', 'GB123456789')
        ->set('po_number', $po)
        ->set('ship_line_one', '1 High Street')
        ->set('ship_city', 'Bromley')
        ->set('ship_postcode', 'BR5 3QX')
        ->call('placeOrder')
        ->assertHasNoErrors();

    return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
}

beforeEach(function (): void {
    config()->set('store.noindex', true);
    $this->member = User::factory()->withAccountTerms()->create(['name' => 'Jo Builder', 'email' => 'jo@builder.example']);
    $this->actingAs($this->member, 'web');
});

// --- Access ---------------------------------------------------------------------------

it('sends a guest from every member page to log in', function (string $route): void {
    auth('web')->logout();

    $this->get(route($route, in_array($route, ['account.orders.show', 'account.invoices.show'], true) ? 'ANY-1' : []))
        ->assertRedirect(route('login'));
})->with(['account.orders', 'account.orders.show', 'account.invoices', 'account.invoices.show']);

it('keeps the member pages out of search engines', function (): void {
    foreach (['account.orders', 'account.invoices', 'account'] as $route) {
        $this->get(route($route))->assertOk()->assertSee('<meta name="robots" content="noindex">', false);
    }
});

it('holds the order and invoice pages back for an unverified email address while verification is required', function (): void {
    config()->set('store.require_email_verification', true);
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified, 'web')->get(route('account.orders'))->assertRedirect(route('verification.notice'));
});

// --- The menu ----------------------------------------------------------------------------

it('shows the member menu on every member page with the current page marked', function (string $route, string $key): void {
    $html = $this->get(route($route))->assertOk()->getContent();

    expect($html)->toContain('data-member-nav')
        ->toContain('href="'.route('account').'"')
        ->toContain('href="'.route('account.orders').'"')
        ->toContain('href="'.route('account.invoices').'"')
        ->toContain('href="'.route('favourites').'"')
        ->toContain('data-member-logout');

    // Exactly one link is the current page.
    expect(preg_match_all('/aria-current="page"/', substr($html, (int) strpos($html, 'data-member-nav'), 1800)))->toBe(1)
        ->and($html)->toMatch('#aria-current="page"\s+data-member-link="'.$key.'"#');
})->with([
    'details' => ['account', 'details'],
    'orders' => ['account.orders', 'orders'],
    'invoices' => ['account.invoices', 'invoices'],
]);

it('gives a signed-in customer the account menu in the header, and a guest none', function (): void {
    $html = $this->get(route('home'))->getContent();

    expect($html)->toContain('data-account-menu')
        ->toContain('data-menu-orders')
        ->toContain('data-menu-invoices')
        ->toContain('data-menu-favourites')
        ->toContain('data-menu-details');

    auth('web')->logout();

    expect($this->get(route('home'))->getContent())->not->toContain('data-account-menu');
});

// --- Orders ---------------------------------------------------------------------------------

it('shows the empty state when the customer has no orders', function (): void {
    $this->get(route('account.orders'))->assertOk()->assertSee('data-orders-empty', false)->assertDontSee('data-orders-table', false);
    $this->get(route('account.invoices'))->assertOk()->assertSee('data-invoices-empty', false);
});

it('lists the customer\'s placed orders, newest first, and never anyone else\'s or an unplaced one', function (): void {
    $first = memberOrder($this->member, 'PO-1');
    $first->update(['placed_at' => now()->subDays(3)]);
    $second = memberOrder($this->member);

    $other = User::factory()->withAccountTerms()->create();
    $theirs = memberOrder($other);

    $draft = memberOrder($this->member);
    $draft->update(['placed_at' => null]);

    $this->actingAs($this->member, 'web');
    $html = $this->get(route('account.orders'))->assertOk()->getContent();

    expect($html)->toContain($first->reference)
        ->toContain($second->reference)
        ->not->toContain($theirs->reference)
        ->not->toContain($draft->reference)
        ->toContain('PO PO-1')
        ->toContain('data-order-status')
        ->toContain('Awaiting Payment')
        ->toContain('href="'.route('account.orders.show', $second->reference).'"')
        ->toContain('href="'.route('account.invoices.show', $second->reference).'"')
        ->and(strpos($html, $second->reference))->toBeLessThan(strpos($html, $first->reference));
});

it('pages the list ten at a time', function (): void {
    $order = memberOrder($this->member);

    foreach (range(1, 11) as $n) {
        $copy = $order->replicate();
        $copy->reference = 'COPY-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $copy->placed_at = now()->subMinutes($n);
        $copy->save();
    }

    $page1 = $this->get(route('account.orders'))->assertOk()->getContent();
    $page2 = $this->get(route('account.orders', ['page' => 2]))->assertOk()->getContent();

    expect(substr_count($page1, 'data-order-row'))->toBe(10)
        ->and(substr_count($page2, 'data-order-row'))->toBe(2)
        ->and($page1)->toContain('data-orders-pagination');
});

it('shows one order with its lines, VAT, totals, address and payment', function (): void {
    $order = memberOrder($this->member, 'PO-9');

    $html = $this->get(route('account.orders.show', $order->reference))->assertOk()->getContent();

    expect($html)->toContain('Order '.$order->reference)
        ->toContain('data-order-page')
        ->toContain('data-order-lines')
        ->toContain('data-order-totals')
        ->toContain('PO-9')
        ->toContain('30 day account')
        ->toContain('Awaiting Payment')
        ->toContain('1 High Street')
        // £50.00 goods + £30.00 delivery, £16.00 VAT, £96.00 total.
        ->toContain('£96.00')
        ->toContain('href="'.route('account.invoices.show', $order->reference).'"');
});

it('answers 404 for another customer\'s order, an unplaced one and one that does not exist', function (): void {
    $other = User::factory()->withAccountTerms()->create();
    $theirs = memberOrder($other);
    $draft = memberOrder($this->member);
    $draft->update(['placed_at' => null]);

    $this->actingAs($this->member, 'web');

    foreach ([$theirs->reference, $draft->reference, 'NOPE-1'] as $reference) {
        $this->get(route('account.orders.show', $reference))->assertNotFound();
        $this->get(route('account.invoices.show', $reference))->assertNotFound();
    }
});

it('does not open orders to a staff member signed in to Lunar', function (): void {
    $order = memberOrder($this->member);
    auth('web')->logout();

    $this->actingAs(Staff::factory()->create(), 'staff')
        ->get(route('account.orders.show', $order->reference))
        ->assertRedirect(route('login'));
});

// --- Invoices -------------------------------------------------------------------------------------

it('lists one invoice per order with its number, date and payment state', function (): void {
    $order = memberOrder($this->member);

    $html = $this->get(route('account.invoices'))->assertOk()->getContent();

    expect($html)->toContain((new Invoice($order))->number())
        ->toContain($order->placed_at->format('j M Y'))
        ->toContain('Payment due by '.$order->placed_at->copy()->addDays(30)->format('j F Y'))
        ->toContain('£96.00');
});

it('shows a tax invoice with the supplier, customer, lines and totals', function (): void {
    $order = memberOrder($this->member, 'PO-5');

    $html = $this->get(route('account.invoices.show', $order->reference))->assertOk()->getContent();

    expect($html)->toContain('>Invoice</h1>')
        ->toContain('data-invoice-number>'.(new Invoice($order))->number())
        ->toContain('data-invoice-date>'.$order->placed_at->format('j M Y'))
        // The supplier's details, from the store identity.
        ->toContain('data-supplier-name>Frontline Holdings Ltd')
        ->toContain('data-supplier-vat')
        ->toContain('VAT Number: 377 3202 94')
        // The customer's, with the VAT number they gave at checkout.
        ->toContain('data-invoice-billing')
        ->toContain('Builder Ltd')
        ->toContain('data-invoice-customer-vat>GB123456789')
        ->toContain('Purchase Order Number')
        ->toContain('PO-5')
        // One goods line, with delivery in the totals: £50.00 goods, £30.00 delivery, £16.00 VAT, £96.00 to pay.
        ->and(substr_count($html, 'data-invoice-line>'))->toBe(1)
        // No pound sign except on the total.
        ->and($html)->toMatch('#data-invoice-subtotal>\s*<th[^>]*>Subtotal</th>\s*<td[^>]*>50\.00</td>#')
        ->toMatch('#data-invoice-delivery-total>\s*<th[^>]*>Delivery</th>\s*<td[^>]*>30\.00</td>#')
        ->toMatch('#<th[^>]*>TOTAL VAT 20%</th>\s*<td[^>]*>16\.00</td>#')
        ->toContain('data-invoice-total>£96.00')
        // On account and unpaid: the due date 30 days on and the bank details.
        ->toContain('<span class="font-bold">Due Date:</span> <span data-invoice-status>'.$order->placed_at->copy()->addDays(30)->format('j M Y').'</span>')
        ->toContain('data-invoice-bank')
        ->toContain('data-print-invoice');
});

it('marks an invoice paid once staff move the order to Payment Received', function (): void {
    $order = memberOrder($this->member);
    $order->update(['status' => 'payment-received']);

    $this->get(route('account.invoices.show', $order->reference))
        ->assertOk()
        ->assertSee('data-invoice-status>Paid', false)
        ->assertDontSee('Due Date:')
        ->assertDontSee('data-invoice-bank', false)
        ->assertDontSee('data-payment-advice', false);

    $this->get(route('account.invoices'))->assertSee('data-invoice-payment>Paid', false);
});

it('works out an invoice from the order: number, date, due date and paid state', function (): void {
    $order = memberOrder($this->member);
    $invoice = new Invoice($order);

    expect($invoice->number())->toBe($order->placed_at->format('dmY').'/001')
        ->and($invoice->onAccount())->toBeTrue()
        ->and($invoice->isPaid())->toBeFalse()
        ->and($invoice->dueDate()->toDateString())->toBe($order->placed_at->copy()->addDays(30)->toDateString())
        ->and($invoice->paymentMethod())->toBe('30 day account');

    // A card order is paid when it is placed and has no due date.
    $order->update(['meta' => array_merge((array) $order->meta, ['payment_method' => 'card'])]);
    $card = new Invoice($order->refresh());

    expect($card->onAccount())->toBeFalse()
        ->and($card->isPaid())->toBeTrue()
        ->and($card->dueDate())->toBeNull()
        ->and($card->paymentStatus())->toBe('Paid')
        ->and($card->paymentMethod())->toBe('Card');
});

it('names an order status the way the admin does, and readably when it has no label', function (): void {
    expect(OrderStatus::label('awaiting-payment'))->toBe('Awaiting Payment')
        ->and(OrderStatus::label('payment-received'))->toBe('Payment Received')
        ->and(OrderStatus::label('some-new-status'))->toBe('Some new status');
});

it('links the confirmation page to the invoice and the orders list', function (): void {
    $order = memberOrder($this->member);

    $this->get(route('checkout.complete', $order->reference))->assertOk()
        ->assertSee('data-view-invoice', false)
        ->assertSee('href="'.route('account.invoices.show', $order->reference).'"', false)
        ->assertSee('href="'.route('account.orders').'"', false);
});
