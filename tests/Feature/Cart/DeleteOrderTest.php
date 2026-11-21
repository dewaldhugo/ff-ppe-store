<?php

declare(strict_types=1);

use App\Admin\Extensions\DeleteOrderAction;
use App\Domain\Orders\DeleteOrder;
use App\Livewire\Checkout\CheckoutPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\OrderResource;
use Lunar\Admin\Filament\Resources\OrderResource\Pages\ListOrders;
use Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder;
use Lunar\Admin\Models\Staff;
use Lunar\Facades\CartSession;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use Lunar\Models\OrderAddress;
use Lunar\Models\OrderLine;
use Lunar\Models\Transaction;
use Tests\Support\CatalogueFactory;

// Staff can delete an order in the admin: on the orders list (row and bulk) and on the order page, for admin
// staff only. The order goes with its lines, addresses and transactions in one transaction, its cart is kept,
// and its invoice number stays on record so it is never given to another order (docs/cart.md, Deleting an
// order).

beforeEach(function (): void {
    config()->set('store.noindex', true);
    config()->set('store.order_notification_emails', '');
    Mail::fake();
    $this->customer = User::factory()->withAccountTerms()->create(['name' => 'Jo Builder', 'email' => 'jo@builder.example']);
    $this->actingAs($this->customer, 'web');
});

/** Places a real 30 day account order through the checkout: two pairs at 50.00 ex VAT. */
function deletableOrder(User $user): Order
{
    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $user->id,
    ]);

    $variant = CatalogueFactory::product('Delete Boot '.Str::random(4), [['colour' => 'Black', 'size' => '9', 'stock' => 50]], 'published', 5000, 'standard')->variants->sole();
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 2, 'meta' => []]);
    CartSession::use($cart);

    Livewire::actingAs($user, 'web')->test(CheckoutPage::class)
        ->set('full_name', 'Jo Builder')
        ->set('email', 'jo@builder.example')
        ->set('phone', '020 8289 5858')
        ->set('company_name', 'Builder Ltd')
        ->set('vat_number', 'GB123456789')
        ->set('ship_line_one', '1 High Street')
        ->set('ship_city', 'Bromley')
        ->set('ship_postcode', 'BR5 3QX')
        ->call('placeOrder')
        ->assertHasNoErrors();

    CartSession::forget(false);

    return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
}

function asAdmin(bool $admin = true): Staff
{
    $staff = Staff::factory()->create(['admin' => $admin]);
    test()->actingAs($staff, 'staff');

    return $staff;
}

// --- Deleting ---------------------------------------------------------------------------------

it('deletes the order with its lines, addresses and payment records', function (): void {
    $order = deletableOrder($this->customer);
    Transaction::factory()->create(['order_id' => $order->id, 'type' => 'capture', 'success' => true]);

    expect(OrderLine::query()->where('order_id', $order->id)->count())->toBeGreaterThan(0)
        ->and(OrderAddress::query()->where('order_id', $order->id)->count())->toBe(2)
        ->and(Transaction::query()->where('order_id', $order->id)->count())->toBe(1);

    app(DeleteOrder::class)($order);

    expect(Order::query()->whereKey($order->id)->exists())->toBeFalse()
        ->and(OrderLine::query()->where('order_id', $order->id)->count())->toBe(0)
        ->and(OrderAddress::query()->where('order_id', $order->id)->count())->toBe(0)
        ->and(Transaction::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('leaves every other order alone', function (): void {
    $keep = deletableOrder($this->customer);
    $delete = deletableOrder($this->customer);

    app(DeleteOrder::class)($delete);

    expect(Order::query()->whereKey($keep->id)->exists())->toBeTrue()
        ->and(OrderLine::query()->where('order_id', $keep->id)->count())->toBeGreaterThan(0)
        ->and(OrderAddress::query()->where('order_id', $keep->id)->count())->toBe(2);
});

it('keeps the cart that made the order, with its link to the order cleared', function (): void {
    $order = deletableOrder($this->customer);
    $cartId = $order->cart_id;

    Cart::query()->whereKey($cartId)->update(['order_id' => $order->id]);

    app(DeleteOrder::class)($order);

    expect(Cart::query()->whereKey($cartId)->exists())->toBeTrue()
        ->and(Cart::query()->whereKey($cartId)->value('order_id'))->toBeNull();
});

it('removes all of it or none of it: a failure part way leaves the order whole', function (): void {
    $order = deletableOrder($this->customer);
    $lines = OrderLine::query()->where('order_id', $order->id)->count();

    // The last step fails, after the lines and addresses were removed.
    Event::listen('eloquent.deleting: '.Order::class, function (): void {
        throw new RuntimeException('The database went away');
    });

    expect(fn () => app(DeleteOrder::class)($order))->toThrow(RuntimeException::class);

    Event::forget('eloquent.deleting: '.Order::class);

    expect(Order::query()->whereKey($order->id)->exists())->toBeTrue()
        ->and(OrderLine::query()->where('order_id', $order->id)->count())->toBe($lines)
        ->and(OrderAddress::query()->where('order_id', $order->id)->count())->toBe(2);
});

// --- The invoice number -----------------------------------------------------------------------

it('keeps the invoice number of a deleted order and never gives it to another order', function (): void {
    $first = deletableOrder($this->customer);
    $second = deletableOrder($this->customer);
    $number = (string) DB::table('invoice_numbers')->where('order_id', $second->id)->value('number');

    expect($number)->toEndWith('/002');

    app(DeleteOrder::class)($second);

    // The number is still on record, with no order.
    expect(DB::table('invoice_numbers')->where('number', $number)->exists())->toBeTrue()
        ->and(DB::table('invoice_numbers')->where('number', $number)->value('order_id'))->toBeNull();

    // The next order placed that day carries on from it: /003, not a second /002.
    $third = deletableOrder($this->customer);

    expect((string) DB::table('invoice_numbers')->where('order_id', $third->id)->value('number'))->toEndWith('/003')
        ->and(DB::table('invoice_numbers')->where('order_id', $first->id)->exists())->toBeTrue();
});

it('has an invoice_numbers table whose order link is cleared, not cascaded, when the order goes', function (): void {
    $column = collect(Schema::getColumns('invoice_numbers'))->firstWhere('name', 'order_id');
    $foreign = collect(Schema::getForeignKeys('invoice_numbers'))->first(fn (array $key): bool => $key['columns'] === ['order_id']);

    expect($column['nullable'])->toBeTrue()
        ->and($foreign['on_delete'])->toBe('set null');
});

// --- The warning ------------------------------------------------------------------------------

it('warns that a placed order keeps its invoice number on record', function (): void {
    $order = deletableOrder($this->customer);
    $warning = app(DeleteOrder::class)->warning($order);

    expect($warning)->toContain('permanently deletes order '.$order->reference)
        ->toContain('cannot be undone')
        ->toContain('invoice number is kept on record and will not be used again')
        ->not->toContain('Stripe');
});

it('warns that deleting an order does not refund a card payment taken for it', function (): void {
    $order = deletableOrder($this->customer);
    Transaction::factory()->create(['order_id' => $order->id, 'type' => 'capture', 'success' => true]);

    expect(app(DeleteOrder::class)->paymentTaken($order))->toBeTrue()
        ->and(app(DeleteOrder::class)->warning($order))->toContain('Deleting the order does not refund the card')->toContain('Stripe');
});

it('does not count a failed or refunded payment as money taken, and says nothing about an invoice for a draft', function (): void {
    $order = deletableOrder($this->customer);
    Transaction::factory()->create(['order_id' => $order->id, 'type' => 'capture', 'success' => false]);
    Transaction::factory()->create(['order_id' => $order->id, 'type' => 'refund', 'success' => true]);
    $order->forceFill(['placed_at' => null])->saveQuietly();

    $warning = app(DeleteOrder::class)->warning($order->refresh());

    expect(app(DeleteOrder::class)->paymentTaken($order))->toBeFalse()
        ->and($warning)->not->toContain('Stripe')->not->toContain('invoice number');
});

// --- In the admin -----------------------------------------------------------------------------

it('deletes an order from the orders list, after a confirmation, for an admin', function (): void {
    $order = deletableOrder($this->customer);
    asAdmin();

    Livewire::test(ListOrders::class)->loadTable()
        ->assertTableActionVisible('delete_order', $order)
        ->assertTableActionHasLabel('delete_order', 'Delete order', $order)
        ->callTableAction('delete_order', $order)
        ->assertNotified('Order '.$order->reference.' deleted');

    expect(Order::query()->whereKey($order->id)->exists())->toBeFalse();
});

it('hides the delete button from staff who are not admins', function (): void {
    $order = deletableOrder($this->customer);
    asAdmin(false);

    Livewire::test(ListOrders::class)->loadTable()->assertTableActionHidden('delete_order', $order);
    Livewire::test(ManageOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('delete_order');

    expect(DeleteOrderAction::mayDelete())->toBeFalse()
        ->and(Order::query()->whereKey($order->id)->exists())->toBeTrue();
});

it('deletes every selected order with the bulk action', function (): void {
    $a = deletableOrder($this->customer);
    $b = deletableOrder($this->customer);
    $keep = deletableOrder($this->customer);
    asAdmin();

    Livewire::test(ListOrders::class)->loadTable()
        ->callTableBulkAction('delete_orders', [$a, $b])
        ->assertNotified('2 orders deleted');

    expect(Order::query()->whereKey($a->id)->exists())->toBeFalse()
        ->and(Order::query()->whereKey($b->id)->exists())->toBeFalse()
        ->and(Order::query()->whereKey($keep->id)->exists())->toBeTrue();
});

it('hides the bulk action from staff who are not admins', function (): void {
    deletableOrder($this->customer);
    asAdmin(false);

    Livewire::test(ListOrders::class)->loadTable()->assertTableBulkActionHidden('delete_orders');
});

it('deletes from the order page and goes back to the list', function (): void {
    $order = deletableOrder($this->customer);
    asAdmin();

    Livewire::test(ManageOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('delete_order')
        ->callAction('delete_order')
        ->assertRedirect(OrderResource::getUrl('index', panel: 'lunar'));

    expect(Order::query()->whereKey($order->id)->exists())->toBeFalse();
});

it('asks before deleting: the confirmation names the order and what is lost', function (): void {
    $order = deletableOrder($this->customer);
    asAdmin();

    Livewire::test(ManageOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('delete_order')
        ->assertActionMounted('delete_order');

    // Nothing is deleted until the confirmation is submitted.
    expect(Order::query()->whereKey($order->id)->exists())->toBeTrue();
});

it('takes the deleted order out of the customer\'s account', function (): void {
    $order = deletableOrder($this->customer);
    app(DeleteOrder::class)($order);

    $this->get(route('account.orders'))->assertOk()->assertDontSee($order->reference);
    $this->get(route('account.invoices'))->assertOk()->assertDontSee($order->reference);
    $this->get(route('checkout.complete', $order->reference))->assertNotFound();
});

// --- Copy rules -------------------------------------------------------------------------------

it('uses UK English and no em dashes', function (): void {
    foreach ([
        'app/Domain/Orders/DeleteOrder.php',
        'app/Admin/Extensions/DeleteOrderAction.php',
        'app/Admin/Extensions/OrderDeleteResourceExtension.php',
        'app/Admin/Extensions/OrderDeleteViewExtension.php',
        'database/migrations/2026_11_21_130000_keep_invoice_numbers_of_deleted_orders.php',
    ] as $file) {
        $text = (string) file_get_contents(base_path($file));

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(color|organization|center|license|favorite)\b/i');
    }
});
