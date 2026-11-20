<?php

declare(strict_types=1);

use App\Domain\Orders\Invoice;
use App\Domain\Orders\InvoiceNumbers;
use App\Livewire\Checkout\CheckoutPage;
use App\Models\User;
use App\Support\Store;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Lunar\Facades\CartSession;
use Lunar\FieldTypes\Text;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use Tests\Support\CatalogueFactory;

// The tax invoice page laid out like the client's sample (docs/accounts.md, Invoices): title and customer, the
// invoice details, the supplier, the lines (colour, UK size, EU size), the totals, the due date, the bank details
// and the payment advice slip. Invoice numbers are DDMMYYYY/NNN (App\Domain\Orders\InvoiceNumbers).

beforeEach(function (): void {
    config()->set('store.noindex', true);
    $this->member = User::factory()->withAccountTerms()->create(['name' => 'Jo Builder', 'email' => 'jo@builder.example']);
    $this->actingAs($this->member, 'web');
});

/**
 * Places a real order on 30 day terms through the checkout. $variants are [colour, size, eu size, quantity].
 *
 * @param  list<array{0: ?string, 1: ?string, 2: ?string, 3: int}>  $variants
 */
function invoiceOrder(User $user, array $variants = [['Light grey', '9', '43', 2]], string $po = ''): Order
{
    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $user->id,
    ]);

    foreach ($variants as $index => [$colour, $size, $eu, $quantity]) {
        $data = ['stock' => 50];

        if ($colour !== null) {
            $data['colour'] = $colour;
        }

        if ($size !== null) {
            $data['size'] = $size;
        }

        $variant = CatalogueFactory::product('Cador S1P Low '.$index.Str::random(4), [$data], 'published', 2790, 'standard')->variants->sole();

        if ($eu !== null) {
            $variant->update(['attribute_data' => ['eu_size' => new Text($eu)]]);
        }

        $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => $quantity, 'meta' => []]);
    }

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

    CartSession::forget(false);

    return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
}

function invoicePage(object $test, Order $order): string
{
    return $test->get(route('account.invoices.show', $order->reference))->assertOk()->getContent();
}

// --- Invoice numbers ------------------------------------------------------------------------

it('gives an order its invoice number when it is placed: the date as DDMMYYYY and the day\'s sequence', function (): void {
    $first = invoiceOrder($this->member);
    $second = invoiceOrder($this->member);

    $today = now()->format('dmY');

    expect(DB::table('invoice_numbers')->where('order_id', $first->id)->value('number'))->toBe($today.'/001')
        ->and(DB::table('invoice_numbers')->where('order_id', $second->id)->value('number'))->toBe($today.'/002')
        ->and((new Invoice($first))->number())->toBe($today.'/001')
        ->and((new Invoice($second))->number())->toBe($today.'/002');
});

it('starts the sequence again at 001 on a new day, and counts each day apart', function (): void {
    $today = invoiceOrder($this->member);
    $yesterday = invoiceOrder($this->member);
    $other = invoiceOrder($this->member);

    // Orders placed on another day: their number carries that day's date and its own sequence.
    DB::table('invoice_numbers')->whereIn('order_id', [$yesterday->id, $other->id])->delete();
    $yesterday->update(['placed_at' => now()->subDay()]);
    $other->update(['placed_at' => now()->subDay()]);

    $numbers = app(InvoiceNumbers::class);
    $day = now()->subDay()->format('dmY');

    expect($numbers->for($yesterday->refresh()))->toBe($day.'/001')
        ->and($numbers->for($other->refresh()))->toBe($day.'/002')
        ->and($numbers->for($today->refresh()))->toBe(now()->format('dmY').'/001');
});

it('never changes a number once given, even if the order date is edited', function (): void {
    $order = invoiceOrder($this->member);
    $number = (new Invoice($order))->number();

    $order->update(['placed_at' => now()->subDays(10)]);

    expect((new Invoice($order->refresh()))->number())->toBe($number)
        ->and(DB::table('invoice_numbers')->where('order_id', $order->id)->count())->toBe(1);
});

it('gives the same number however many times it is asked for', function (): void {
    $order = invoiceOrder($this->member);
    $numbers = app(InvoiceNumbers::class);

    expect($numbers->assign($order))->toBe($numbers->assign($order))
        ->and($numbers->has($order))->toBeTrue()
        ->and(DB::table('invoice_numbers')->count())->toBe(1);
});

it('gives a number the first time an invoice is looked at when an order somehow has none', function (): void {
    $order = invoiceOrder($this->member);
    DB::table('invoice_numbers')->where('order_id', $order->id)->delete();

    expect(app(InvoiceNumbers::class)->has($order))->toBeFalse()
        ->and((new Invoice($order->refresh()))->number())->toBe(now()->format('dmY').'/001')
        ->and(app(InvoiceNumbers::class)->has($order))->toBeTrue();
});

it('refuses a number for an order that is not placed', function (): void {
    $order = invoiceOrder($this->member);
    DB::table('invoice_numbers')->where('order_id', $order->id)->delete();
    $order->forceFill(['placed_at' => null])->saveQuietly();

    app(InvoiceNumbers::class)->assign($order->refresh());
})->throws(LogicException::class);

it('keeps two orders from sharing a number', function (): void {
    $first = invoiceOrder($this->member);
    $second = invoiceOrder($this->member);

    expect(fn () => DB::table('invoice_numbers')->where('order_id', $second->id)->update([
        'invoice_date' => DB::table('invoice_numbers')->where('order_id', $first->id)->value('invoice_date'),
        'sequence' => DB::table('invoice_numbers')->where('order_id', $first->id)->value('sequence'),
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

// --- The page -------------------------------------------------------------------------------

it('lays the invoice out like the sample: title and customer, details, then the supplier', function (): void {
    $order = invoiceOrder($this->member, po: 'HYG/0504');
    $html = invoicePage($this, $order);

    $positions = [
        'Tax invoice' => strpos($html, 'Tax invoice'),
        'customer' => strpos($html, 'data-invoice-billing'),
        'date' => strpos($html, 'Invoice Date'),
        'number' => strpos($html, 'Invoice Number'),
        'po' => strpos($html, 'Purchase Order Number'),
        'vat' => strpos($html, 'data-invoice-customer-vat'),
        'supplier' => strpos($html, 'data-invoice-supplier'),
        'lines' => strpos($html, 'data-invoice-lines'),
    ];

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toMatch('#<h1[^>]*uppercase[^>]*>Tax invoice</h1>#')
        ->and(array_values($positions))->toBe(array_values(collect($positions)->sort()->all()))
        ->and($html)->toContain('data-invoice-date>'.$order->placed_at->format('j M Y'))
        ->toContain('data-invoice-po>HYG/0504')
        ->toContain('data-invoice-customer-vat>GB123456789')
        ->toContain('VAT Number: '.Store::vatNumber())
        ->toContain(e(Store::legalName()))
        ->toContain(e(Store::email()));
});

it('has a line for each size with colour, UK size, EU size, quantity, unit price, VAT rate and amount', function (): void {
    $order = invoiceOrder($this->member, [['Light grey', '9', '43', 2], ['Light grey', '8', '42', 3]]);
    $html = invoicePage($this, $order);

    preg_match('#<thead.*?</thead>#s', $html, $head);
    preg_match_all('#<th scope="col"[^>]*>\s*(.*?)\s*</th>#s', $head[0], $headings);

    expect($headings[1])->toBe(['Description', 'Colour', 'UK Size', 'EU Size', 'Quantity', 'Unit Price', 'VAT', 'Amount'])
        ->and(substr_count($html, 'data-invoice-line>'))->toBe(2);

    preg_match_all('#<tr data-invoice-line>(.*?)</tr>#s', $html, $rows);
    $first = preg_replace('/\s+/', ' ', strip_tags($rows[1][0]));

    // £27.90 a pair ex VAT, 20% VAT, 2 pairs: £55.80.
    expect($rows[1][0])->toContain('data-line-colour>Light grey')
        ->toContain('data-line-size>9')
        ->toContain('data-line-eu>43')
        ->and($first)->toContain('2')->toContain('£27.90')->toContain('20%')->toContain('£55.80')
        ->and($rows[1][1])->toContain('data-line-size>8')->toContain('data-line-eu>42')->toContain('£83.70');
});

it('shows the totals as subtotal, delivery, total VAT and total', function (): void {
    // 5 pairs at £27.90 = £139.50, under the free delivery amount, so £30.00 delivery: £169.50 net, £33.90 VAT.
    $order = invoiceOrder($this->member, [['Light grey', '9', '43', 5]]);
    $html = invoicePage($this, $order);

    preg_match('#<dl[^>]*data-invoice-totals.*?</dl>#s', $html, $totals);
    $text = preg_replace('/\s+/', ' ', strip_tags($totals[0]));

    expect($text)->toContain('Subtotal £139.50')
        ->toContain('Delivery £30.00')
        ->toContain('TOTAL VAT 20% £33.90')
        ->toContain('TOTAL £203.40')
        ->and(strpos($text, 'Subtotal'))->toBeLessThan(strpos($text, 'Delivery'))
        ->and(strpos($text, 'Delivery'))->toBeLessThan(strpos($text, 'TOTAL VAT'))
        ->and($html)->toContain('data-invoice-total>£203.40');
});

it('adds up: subtotal plus delivery plus VAT is the total, and the delivery is not a line', function (): void {
    $order = invoiceOrder($this->member, [['Light grey', '9', '43', 5]]);
    $invoice = new Invoice($order->load('lines'));

    expect($invoice->goodsLines())->toHaveCount(1)
        ->and($invoice->deliveryLines())->toHaveCount(1)
        ->and($invoice->subtotal()->value + $invoice->delivery()->value + $order->tax_total->value)->toBe($order->total->value)
        ->and($invoice->singleRate())->toBe(20.0);
});

it('leaves the colour and size columns out when no line has them', function (): void {
    $order = invoiceOrder($this->member, [[null, null, null, 1]]);
    $html = invoicePage($this, $order);

    expect($html)->not->toContain('data-line-colour')
        ->not->toContain('data-line-size')
        ->not->toContain('data-line-eu')
        ->not->toContain('>EU Size<')
        ->and($html)->toContain('Quantity')->toContain('Unit Price')->toContain('Amount');
});

it('shows a size with no EU size without an EU column', function (): void {
    $order = invoiceOrder($this->member, [['Black', '9', null, 1]]);
    $html = invoicePage($this, $order);

    expect($html)->toContain('data-line-size>9')->not->toContain('data-line-eu');
});

it('keeps the colour and sizes as they were when the order was placed', function (): void {
    $order = invoiceOrder($this->member);
    $line = $order->lines()->where('type', 'physical')->sole();

    expect($line->meta['colour'])->toBe('Light grey')
        ->and($line->meta['size'])->toBe('9')
        ->and($line->meta['size_is_uk'])->toBeTrue()
        ->and($line->meta['eu_size'])->toBe('43');

    // The variant changes later: the invoice does not.
    $variant = $line->purchasable;
    $variant->values()->detach();
    $variant->update(['attribute_data' => ['eu_size' => new Text('99')]]);

    $html = invoicePage($this, $order);

    expect($html)->toContain('data-line-colour>Light grey')->toContain('data-line-eu>43')->not->toContain('>99<');
});

it('shows the code and the option text for a line with no snapshot', function (): void {
    $order = invoiceOrder($this->member);
    $line = $order->lines()->where('type', 'physical')->sole();
    $line->update(['meta' => [], 'option' => 'Light grey, 9']);

    $html = invoicePage($this, $order);

    expect($html)->toContain('Code '.$line->identifier.', Light grey, 9')
        ->not->toContain('data-line-colour');
});

it('has the due date, the bank details and the payment advice slip while an account invoice is unpaid', function (): void {
    config()->set('store.bank', [
        'account_name' => 'Test Holdings Limited',
        'bank_name' => 'Test Bank',
        'sort_code' => '00-00-00',
        'account_number' => '12345678',
        'iban' => 'GB00 TEST 0000 0012 3456 78',
        'address' => '1 Test Street, London',
    ]);

    $order = invoiceOrder($this->member);
    $html = invoicePage($this, $order);
    $number = (new Invoice($order))->number();
    $due = $order->placed_at->copy()->addDays(30)->format('j M Y');

    preg_match('#<section[^>]*data-invoice-bank.*?</section>#s', $html, $bank);
    preg_match('#<section[^>]*data-payment-advice.*?</section>#s', $html, $advice);

    expect($html)->toContain('<span class="font-bold">Due Date:</span> <span data-invoice-status>'.$due.'</span>')
        ->and($bank[0])->toContain('Test Holdings Limited')
        ->toContain('Test Bank')
        ->toContain('00-00-00')
        ->toContain('12345678')
        ->toContain('GB00 TEST 0000 0012 3456 78')
        ->toContain('1 Test Street, London')
        ->and($advice[0])->toContain('Payment advice')
        ->toContain('Builder Ltd')
        ->toContain('data-advice-number>'.$number)
        ->toContain('data-advice-amount>£'.number_format($order->total->value / 100, 2))
        ->toContain('data-advice-due>'.$due)
        ->toContain('Amount Enclosed')
        ->toContain('Enter the amount you are paying above')
        // The slip prints on its own page, below the bank details.
        ->and($advice[0])->toContain('print:break-before-page')
        ->and(strpos($html, 'data-invoice-bank'))->toBeLessThan(strpos($html, 'data-payment-advice'));
});

it('prints TBC for a bank detail that is not set yet', function (): void {
    config()->set('store.bank', ['account_name' => 'Test Holdings Limited']);

    expect(Store::bankDetails())->toBe([
        'Account name' => 'Test Holdings Limited',
        'Bank' => 'TBC',
        'Sort code' => 'TBC',
        'Account number' => 'TBC',
        'IBAN' => 'TBC',
        'Bank address' => 'TBC',
    ]);
});

it('has the bank details fixed in config/store.php, with no environment variable', function (): void {
    $file = require config_path('store.php');
    $source = (string) file_get_contents(config_path('store.php'));

    expect($file['bank'])->toBe([
        'account_name' => 'Frontline Holdings Limited',
        'bank_name' => 'Wise Bank',
        'sort_code' => '23-08-01',
        'account_number' => '63606297',
        'iban' => 'GB90 TRWI 2308 0163 6062 97',
        'address' => '56 Shoreditch High Street, London, E1 6JJ, United Kingdom',
    ])
        ->and($source)->not->toContain('STORE_BANK');

    foreach (['.env.example', '.env.production.example'] as $example) {
        expect((string) file_get_contents(base_path($example)))->not->toContain('STORE_BANK');
    }

    // Nothing to set: an unpaid account invoice prints the real details, never TBC.
    expect(Store::bankDetails())->not->toContain('TBC')
        ->and(Store::bankDetails()['Sort code'])->toBe('23-08-01');
});

it('shows a paid invoice with its status and no bank details or payment advice slip', function (): void {
    $order = invoiceOrder($this->member);
    $order->update(['status' => 'payment-received']);

    $html = invoicePage($this, $order);

    expect($html)->toContain('<span class="font-bold">Status:</span> <span data-invoice-status>Paid</span>')
        ->not->toContain('Due Date')
        ->not->toContain('data-invoice-bank')
        ->not->toContain('data-payment-advice');
});

it('shows a card invoice as paid, with no bank details or payment advice slip', function (): void {
    $order = invoiceOrder($this->member);
    $order->update(['meta' => array_merge((array) $order->meta, ['payment_method' => 'card'])]);

    $html = invoicePage($this, $order);

    expect($html)->toContain('data-invoice-status>Paid')
        ->not->toContain('data-invoice-bank')
        ->not->toContain('data-payment-advice');
});

it('ends with the company registration number and registered office', function (): void {
    config()->set('store.company_number', '13184904');
    config()->set('store.registered_office', '7 Lynnwood Court, Priestlands Place, Lymington, Hampshire, SO41 9GA');

    $html = invoicePage($this, invoiceOrder($this->member));

    preg_match('#<footer[^>]*data-invoice-footer[^>]*>(.*?)</footer>#s', $html, $footer);
    $text = trim(preg_replace('/\s+/', ' ', $footer[1]));

    expect($text)->toContain('Company Registration No: 13184904.')
        ->toContain('registered in England and Wales')
        ->toContain('Registered Office: 7 Lynnwood Court, Priestlands Place, Lymington, Hampshire, SO41 9GA.');
});

it('only ever shows a customer their own invoice', function (): void {
    $order = invoiceOrder($this->member);
    $stranger = User::factory()->withAccountTerms()->create();

    $this->actingAs($stranger, 'web')->get(route('account.invoices.show', $order->reference))->assertNotFound();
});

it('uses UK English and no em dashes in its copy', function (): void {
    foreach (['resources/views/account/invoices/show.blade.php', 'app/Domain/Orders/InvoiceNumbers.php', 'app/Domain/Orders/Invoice.php', 'app/Domain/Orders/SnapshotLineOptions.php'] as $file) {
        $text = (string) file_get_contents(base_path($file));

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(color|organization|center)\b/i');
    }
});
