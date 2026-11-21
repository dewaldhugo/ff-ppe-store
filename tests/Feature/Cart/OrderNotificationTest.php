<?php

declare(strict_types=1);

use App\Livewire\Checkout\CheckoutPage;
use App\Mail\OrderPlaced;
use App\Models\User;
use App\Support\Queues;
use App\Support\Store;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Lunar\Facades\CartSession;
use Lunar\FieldTypes\Text;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use Tests\Support\CatalogueFactory;

// Staff are emailed when an order is placed (docs/cart.md, Order notification): the recipients are
// STORE_ORDER_NOTIFICATION_EMAILS, the mail is queued on the default queue, it goes once per order, and a mail
// failure never stops the order. The addresses here are made up: no real address is used in a test.

const NOTIFY_TO = ['staff.one@example.test', 'staff.two@example.test', 'staff.three@example.test'];

beforeEach(function (): void {
    config()->set('store.noindex', true);
    config()->set('store.order_notification_emails', implode(',', NOTIFY_TO));
    Mail::fake();
    $this->customer = User::factory()->withAccountTerms()->create(['name' => 'Jo Builder', 'email' => 'jo@builder.example']);
    $this->actingAs($this->customer, 'web');
});

/** Places a real order on 30 day terms through the checkout: one pair, Light grey, UK 9, EU 43. */
function notifyOrder(User $user, string $po = '', string $supplier = '', string $company = 'Builder Ltd'): Order
{
    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $user->id,
    ]);

    $variant = CatalogueFactory::product('Notify Boot '.Str::random(4), [['colour' => 'Light grey', 'size' => '9', 'stock' => 50]], 'published', 5000, 'standard')->variants->sole();
    $variant->update(['attribute_data' => ['eu_size' => new Text('43')]]);
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 2, 'meta' => []]);
    CartSession::use($cart);

    Livewire::actingAs($user, 'web')->test(CheckoutPage::class)
        ->set('full_name', 'Jo Builder')
        ->set('email', 'jo@builder.example')
        ->set('phone', '020 8289 5858')
        ->set('company_name', $company)
        ->set('vat_number', 'GB123456789')
        ->set('po_number', $po)
        ->set('supplier_number', $supplier)
        ->set('ship_line_one', '1 High Street')
        ->set('ship_city', 'Bromley')
        ->set('ship_postcode', 'BR5 3QX')
        ->set('notes', 'Deliver to Receiving Bay 2')
        ->call('placeOrder')
        ->assertHasNoErrors();

    CartSession::forget(false);

    return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
}

// --- Who is emailed ---------------------------------------------------------------------------

it('reads the recipients from STORE_ORDER_NOTIFICATION_EMAILS: valid, distinct, any separator', function (): void {
    config()->set('store.order_notification_emails', 'A@x.test, b@x.test; a@x.test  not-an-email c@x.test,,');

    expect(Store::orderNotificationEmails())->toBe(['a@x.test', 'b@x.test', 'c@x.test']);

    config()->set('store.order_notification_emails', '');
    expect(Store::orderNotificationEmails())->toBe([]);

    config()->set('store.order_notification_emails', null);
    expect(Store::orderNotificationEmails())->toBe([]);
});

it('takes the recipients from the environment, with none set by default, and keeps the addresses out of the repository', function (): void {
    $source = (string) file_get_contents(config_path('store.php'));

    expect($source)->toContain("env('STORE_ORDER_NOTIFICATION_EMAILS', '')")
        ->and($source)->not->toMatch('/@(gmail|example)\./');

    foreach (['.env.example', '.env.production.example'] as $example) {
        expect((string) file_get_contents(base_path($example)))->toContain("\nSTORE_ORDER_NOTIFICATION_EMAILS=\n");
    }
});

// --- When it is sent --------------------------------------------------------------------------

it('emails every recipient once when an order is placed', function (): void {
    $order = notifyOrder($this->customer, 'PO-99986', 'SUP-9');

    Mail::assertQueuedCount(1);
    Mail::assertQueued(OrderPlaced::class, function (OrderPlaced $mail) use ($order): bool {
        foreach (NOTIFY_TO as $address) {
            if (! $mail->hasTo($address)) {
                return false;
            }
        }

        return $mail->order->is($order);
    });
});

it('sends nothing when no recipient is set', function (): void {
    config()->set('store.order_notification_emails', '');

    notifyOrder($this->customer);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('emails once per order: later changes to a placed order send nothing more', function (): void {
    $order = notifyOrder($this->customer);

    $order->update(['status' => 'payment-received']);
    $order->update(['notes' => 'Changed']);
    // Even editing the placed date is not a new order.
    $order->update(['placed_at' => now()->subDay()]);

    Mail::assertQueuedCount(1);
});

it('emails when a draft becomes placed, as a card order does when Stripe confirms the payment', function (): void {
    $order = notifyOrder($this->customer);

    // Back to a draft, as a card order is until the payment succeeds.
    $order->forceFill(['placed_at' => null])->saveQuietly();
    Mail::fake();

    $order->update(['notes' => 'Still a draft']);
    Mail::assertNothingQueued();

    $order->update(['placed_at' => now()]);
    Mail::assertQueuedCount(1);

    // And only that once.
    $order->update(['status' => 'payment-received']);
    Mail::assertQueuedCount(1);
});

it('does not stop the order when the mail cannot be sent, and reports it', function (): void {
    Exceptions::fake();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    $order = notifyOrder($this->customer);

    expect($order->placed_at)->not->toBeNull()->and($order->status)->toBe('awaiting-payment');
    Exceptions::assertReported(RuntimeException::class);
});

// --- The email --------------------------------------------------------------------------------

it('is queued on the default queue, so the customer never waits for the mail server', function (): void {
    $order = notifyOrder($this->customer);
    $mail = new OrderPlaced($order);

    expect($mail)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail->queue)->toBe(Queues::DEFAULT);
});

it('carries the order: who, payment, PO and supplier number, delivery, items, totals and the admin link', function (): void {
    $order = notifyOrder($this->customer, 'PO-99986', 'SUP-9');
    $mail = new OrderPlaced($order);
    $text = $mail->render();

    expect($mail->envelope()->subject)->toBe('New order '.$order->reference.' from Builder Ltd (£156.00)')
        ->and($text)->toContain('Order: '.$order->reference)
        ->toContain('Invoice number: '.now()->format('dmY').'/001')
        ->toContain('Payment: 30 Day Account, due '.$order->placed_at->copy()->addDays(30)->format('j M Y'))
        ->toContain('PO number: PO-99986')
        ->toContain('Supplier number: SUP-9')
        ->toContain("CUSTOMER\nBuilder Ltd")
        ->toContain('Contact: Jo Builder')
        ->toContain('Email: jo@builder.example')
        ->toContain('Phone: 020 8289 5858')
        ->toContain('VAT number: GB123456789')
        ->toContain("DELIVER TO\nBuilder Ltd\nJo Builder\n1 High Street\nBromley\nBR5 3QX")
        ->toContain('Delivery instructions: Deliver to Receiving Bay 2')
        ->toContain('BILL TO')
        // 2 pairs at £50.00 ex VAT, with the colour and sizes as they were when the order was placed.
        ->toContain('- 2 x Notify Boot')
        ->toContain('Light grey, UK 9, EU 43')
        ->toContain('50.00 each, 100.00')
        ->toContain('Subtotal: 100.00')
        ->toContain('Delivery: 30.00')
        ->toContain('VAT: 26.00')
        ->toContain('TOTAL: £156.00')
        ->toContain('/admin/orders/'.$order->id);
});

it('leaves the PO and supplier number lines out when the order has none', function (): void {
    $order = notifyOrder($this->customer);
    $text = (new OrderPlaced($order))->render();

    expect($text)->not->toContain('PO number')->not->toContain('Supplier number');
});

it('says a card order is paid', function (): void {
    $order = notifyOrder($this->customer);
    $order->update(['meta' => array_merge((array) $order->meta, ['payment_method' => 'card'])]);

    expect((new OrderPlaced($order->refresh()))->render())->toContain('Payment: Credit Card, paid')->not->toContain('due');
});

it('is plain text, so names with an ampersand are not escaped', function (): void {
    $order = notifyOrder($this->customer, company: 'Smith & Sons <Ltd>');
    $text = (new OrderPlaced($order))->render();

    expect($text)->toContain('Smith & Sons <Ltd>')->not->toContain('&amp;');
});

it('keeps the subject on one line, so a crafted company name cannot add mail headers', function (): void {
    $order = notifyOrder($this->customer);
    $order->billingAddress->update(['company_name' => "Evil Ltd\r\nBcc: victim@example.test"]);

    $subject = (new OrderPlaced($order->refresh()))->envelope()->subject;

    expect($subject)->not->toContain("\n")->not->toContain("\r")
        ->and($subject)->toContain('Evil Ltd Bcc: victim@example.test');
});

it('uses UK English and no em dashes in its copy', function (): void {
    foreach (['app/Mail/OrderPlaced.php', 'app/Domain/Orders/NotifyStaffOfNewOrder.php', 'resources/views/mail/order-placed.blade.php'] as $file) {
        $text = (string) file_get_contents(base_path($file));

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(color|organization|center)\b/i');
    }
});
