<?php

declare(strict_types=1);

use App\Admin\Extensions\CustomerSupplierNumberResourceExtension;
use App\Domain\Orders\Invoice;
use App\Domain\Orders\InvoiceNumbers;
use App\Livewire\Checkout\CheckoutPage;
use App\Models\User;
use App\Support\Store;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\CustomerResource\Pages\EditCustomer;
use Lunar\Admin\Models\Staff;
use Lunar\DataTypes\Price;
use Lunar\Facades\CartSession;
use Lunar\FieldTypes\Text;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Tests\Support\CatalogueFactory;

// The invoice page laid out from the client's template (docs/accounts.md, Invoices): the logo and the sender top
// left, INVOICE and its details top right, the Bill to and Deliver to boxes, the lines (colour, UK size),
// the totals, then the payment method with the due date and bank details or "Status: Paid", and the footer.
// Invoice numbers are DDMMYYYY/NNN (App\Domain\Orders\InvoiceNumbers). The supplier number is a customer field
// (set by staff in the admin, filled in on the checkout) printed on the invoice.

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
function invoiceCart(User $user, array $variants = [['Light grey', '9', '43', 2]]): Cart
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

    return $cart;
}

/**
 * Places a real order on 30 day terms through the checkout. $variants are [colour, size, eu size, quantity].
 *
 * @param  list<array{0: ?string, 1: ?string, 2: ?string, 3: int}>  $variants
 */
function invoiceOrder(User $user, array $variants = [['Light grey', '9', '43', 2]], string $po = '', ?string $supplier = null): Order
{
    invoiceCart($user, $variants);

    Livewire::actingAs($user, 'web')->test(CheckoutPage::class)
        ->set('full_name', 'Jo Builder')
        ->set('email', 'jo@builder.example')
        ->set('phone', '020 8289 5858')
        ->set('company_name', 'Builder Ltd')
        ->set('vat_number', 'GB123456789')
        ->set('po_number', $po)
        ->set('supplier_number', $supplier ?? '')
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
    ]))->toThrow(UniqueConstraintViolationException::class);
});

// --- The page -------------------------------------------------------------------------------

it('lays the invoice out from the template: logo and sender top left, INVOICE and its details top right, then the two boxes', function (): void {
    $order = invoiceOrder($this->member, po: 'HYG/0504', supplier: '38858');
    $html = invoicePage($this, $order);

    $positions = [
        'logo' => strpos($html, 'data-invoice-logo'),
        'sender' => strpos($html, 'data-supplier-name'),
        'title' => strpos($html, '>Tax invoice</h1>'),
        'date' => strpos($html, 'Invoice Date:'),
        'number' => strpos($html, 'Invoice Number:'),
        'po' => strpos($html, 'Purchase Order Number:'),
        'vat' => strpos($html, 'data-invoice-customer-vat'),
        'supplier number' => strpos($html, 'Supplier Number:'),
        'bill to' => strpos($html, 'data-invoice-billing'),
        'deliver to' => strpos($html, 'data-invoice-delivery'),
        'lines' => strpos($html, 'data-invoice-lines'),
    ];

    expect(substr_count($html, '<h1'))->toBe(1)
        // TAX INVOICE is big and bold, in capitals.
        ->and($html)->toMatch('#<h1[^>]*text-5xl[^>]*font-extrabold[^>]*uppercase[^>]*>Tax invoice</h1>#')
        ->and(array_values($positions))->toBe(array_values(collect($positions)->sort()->all()))
        ->and($html)->toContain('data-invoice-date>'.$order->placed_at->format('j M Y'))
        ->toContain('data-invoice-po>HYG/0504')
        ->toContain('data-invoice-customer-vat>GB123456789')
        ->toContain('data-invoice-supplier-number>38858');
});

it('prints the sender, fixed in config, under the logo, with the company name bold and bigger', function (): void {
    $html = invoicePage($this, invoiceOrder($this->member));

    preg_match('#<div[^>]*data-invoice-supplier>(.*?)</div>#s', $html, $block);
    preg_match('#<img[^>]*data-invoice-logo[^>]*>#', $html, $logo);
    $lines = array_values(array_filter(array_map(fn (string $line): string => trim(html_entity_decode($line)), explode("\n", trim(strip_tags(preg_replace('#</p>#', "</p>\n", $block[1])))))));

    expect($logo[0])->toContain('images/frontline-logo.png')->toContain('alt="Frontline Safety Gear"')
        ->and($lines)->toBe([
            'Frontline Holdings Ltd',
            'Frontline Footwear Ltd',
            '7 Lynnwood Court, Priestlands Place, Lymington, SO41 9GA',
            'VAT Number: 377 3202 94',
            'accounts@frontlinesafetygear.com',
            'www.frontlinesafetygear.com',
        ])
        ->and($block[1])->toMatch('#<p[^>]*text-xl[^>]*font-bold[^>]*data-supplier-name>Frontline Holdings Ltd</p>#');
});

it('has the sender fixed in config/store.php, with no environment variable', function (): void {
    $file = require config_path('store.php');
    $source = (string) file_get_contents(config_path('store.php'));

    expect($file['invoice'])->toBe([
        'name' => 'Frontline Holdings Ltd',
        'trading_as' => 'Frontline Footwear Ltd',
        'address' => '7 Lynnwood Court, Priestlands Place, Lymington, SO41 9GA',
        'vat_number' => '377 3202 94',
        'email' => 'accounts@frontlinesafetygear.com',
        'website' => 'www.frontlinesafetygear.com',
        'company_number' => '13184904',
        'registered_office' => 'Attention: Frontline Footwear Ltd, 7 Lynnwood Court, Priestlands Place, Lymington, SO41 9GA',
    ])
        ->and(Store::invoice('vat_number'))->toBe('377 3202 94')
        ->and($source)->not->toContain('INVOICE_');
});

it('puts the customer\'s name, address and VAT number in Bill to, and the delivery address in Deliver to', function (): void {
    $html = invoicePage($this, invoiceOrder($this->member));

    preg_match('#<section[^>]*data-invoice-billing.*?</section>#s', $html, $bill);
    preg_match('#<section[^>]*data-invoice-delivery.*?</section>#s', $html, $deliver);

    $bill = preg_replace('/\s+/', ' ', strip_tags($bill[0]));
    $deliver = preg_replace('/\s+/', ' ', strip_tags($deliver[0]));

    expect($bill)->toContain('Bill to')->toContain('Builder Ltd')->toContain('Jo Builder')->toContain('1 High Street')->toContain('Bromley')->toContain('BR5 3QX')->toContain('VAT Number: GB123456789')
        ->and($deliver)->toContain('Deliver to')->toContain('1 High Street')->toContain('Bromley')->toContain('BR5 3QX')
        ->not->toContain('VAT Number');
});

it('makes the dark bars print: backgrounds are switched off by browsers unless asked for', function (): void {
    $html = invoicePage($this, invoiceOrder($this->member));

    preg_match('#<h2[^>]*>Bill to</h2>#', $html, $bar);
    preg_match('#<thead[^>]*>#', $html, $head);

    // The Bill to and Deliver to bars are dark; the lines table's header is light grey. Both must print.
    expect($bar[0])->toContain('bg-brand-blue')->toContain('print:[print-color-adjust:exact]')
        ->and($head[0])->toContain('bg-surface-muted')->toContain('print:[print-color-adjust:exact]')
        // The site header is left out of the print, so the invoice carries its own logo.
        ->and($html)->toMatch('#<header[^>]*print:hidden[^>]*data-checkout-header#');
});

it('prints on one A4 page with no browser header or footer: no page margin, a smaller root size, tighter spacing', function (): void {
    $html = invoicePage($this, invoiceOrder($this->member));

    preg_match('#<style>(.*?)</style>#s', $html, $style);
    preg_match('#<article[^>]*>#', $html, $article);
    preg_match('#<div[^>]*data-invoice>#', $html, $wrapper);
    preg_match('#<main[^>]*>\s*<div[^>]*>#', $html, $main);

    // A page margin of 0 leaves the browser no room for its URL, page title, date and page numbers.
    expect(preg_replace('/\s+/', ' ', $style[1]))->toContain('@page { size: A4; margin: 0; }')
        // One smaller root size scales every rem-based size down together, in the print only.
        ->toContain('@media print { html { font-size: 12px; } }')
        // The article carries its own padding, and nothing narrows the page.
        ->and($article[0])->toContain('print:p-[11mm]')
        ->and($wrapper[0])->toContain('print:max-w-none')
        ->and($main[0])->toContain('print:max-w-none')->toContain('print:p-0')
        // Tighter in the print: the code goes on the description's line, rows and boxes are shorter.
        ->and($html)->toContain('print:inline')
        ->toContain('print:py-1.5')
        ->toContain('print:min-h-0')
        ->toContain('print:break-inside-avoid');
});

it('prints the invoice only: the site header and the buttons are left out of the print', function (): void {
    $html = invoicePage($this, invoiceOrder($this->member));

    preg_match('#<p class="[^"]*print:hidden[^"]*">.*?data-print-invoice#s', $html, $buttons);

    expect($html)->toMatch('#<header[^>]*print:hidden[^>]*data-checkout-header#')
        ->and($buttons)->not->toBeEmpty()
        ->and($html)->toContain('print:bg-white');
});

it('prints the supplier number only when the customer gave one', function (): void {
    $without = invoicePage($this, invoiceOrder($this->member));

    expect($without)->not->toContain('Supplier Number')->not->toContain('data-invoice-supplier-number');
});

it('has a line for each size with colour, UK size, EU size, quantity, unit price, VAT rate and amount', function (): void {
    $order = invoiceOrder($this->member, [['Light grey', '9', '43', 2], ['Light grey', '8', '42', 3]]);
    $html = invoicePage($this, $order);

    preg_match('#<thead.*?</thead>#s', $html, $head);
    preg_match_all('#<th scope="col"[^>]*>\s*(.*?)\s*</th>#s', $head[0], $headings);

    expect($headings[1])->toBe(['Description', 'Colour', 'UK Size', 'EU Size', 'Quantity', 'Unit Price', 'VAT', 'Amount'])
        ->and(substr_count($html, 'data-invoice-line>'))->toBe(2);

    preg_match_all('#<tr[^>]*data-invoice-line>(.*?)</tr>#s', $html, $rows);
    $first = preg_replace('/\s+/', ' ', strip_tags($rows[1][0]));

    // The lines are sorted by product name first, and each size here is its own product (named in the order they were added), so
    // the size 9 is the first row and the size 8 the second. (Sizes within one product run smallest first: AdminInvoicesTest.)
    // £27.90 a pair ex VAT, 20% VAT, 2 pairs of the size 9: £55.80, and 3 pairs of the size 8: £83.70.
    $second = preg_replace('/\s+/', ' ', strip_tags($rows[1][1]));

    expect($rows[1][0])->toContain('data-line-colour>Light grey')
        ->toContain('data-line-size>9')
        ->toContain('data-line-eu>43')
        ->and($first)->toContain('2')->toContain('27.90')->toContain('20%')->toContain('55.80')
        ->and($rows[1][1])->toContain('data-line-size>8')->toContain('data-line-eu>42')
        ->and($second)->toContain('3')->toContain('83.70');
});

it('shows the totals as subtotal, delivery, total VAT and total', function (): void {
    // 5 pairs at £27.90 = £139.50, under the free delivery amount, so £30.00 delivery: £169.50 net, £33.90 VAT.
    $order = invoiceOrder($this->member, [['Light grey', '9', '43', 5]]);
    $html = invoicePage($this, $order);

    preg_match('#<tbody[^>]*data-invoice-totals.*?</tbody>#s', $html, $totals);
    $text = preg_replace('/\s+/', ' ', strip_tags($totals[0]));

    expect($text)->toContain('Subtotal 139.50')
        ->toContain('Delivery 30.00')
        ->toContain('TOTAL VAT 20% 33.90')
        ->toContain('TOTAL £203.40')
        ->and(strpos($text, 'Subtotal'))->toBeLessThan(strpos($text, 'Delivery'))
        ->and(strpos($text, 'Delivery'))->toBeLessThan(strpos($text, 'TOTAL VAT'))
        ->and($html)->toContain('data-invoice-total>£203.40');
});

it('shows the pound sign on the total only: every other amount is a plain number', function (): void {
    foreach ([invoiceOrder($this->member, [['Light grey', '9', '43', 5]]), invoiceOrder($this->member, [['Light grey', '9', '43', 25]])] as $order) {
        $html = invoicePage($this, $order);

        preg_match('#<article.*?</article>#s', $html, $article);
        preg_match('#<table[^>]*data-invoice-lines.*?</table>#s', $article[0], $table);

        // One pound sign in the whole invoice, and it is on the TOTAL.
        expect(substr_count($article[0], '£'))->toBe(1)
            ->and($article[0])->toMatch('#data-invoice-total>£[0-9,]+\.[0-9]{2}</td>#')
            // The line amounts and the other totals rows are numbers.
            ->and($table[0])->toMatch('#<td[^>]*>27\.90</td>#')
            ->toMatch('#data-invoice-subtotal>\s*<th[^>]*>Subtotal</th>\s*<td[^>]*>[0-9,]+\.[0-9]{2}</td>#');
    }
});

it('words an amount as a plain number from whole pence', function (): void {
    $price = fn (int $pence): Price => new Price($pence, Currency::getDefault(), 1);

    expect(Invoice::amount($price(2790)))->toBe('27.90')
        ->and(Invoice::amount($price(5)))->toBe('0.05')
        ->and(Invoice::amount($price(0)))->toBe('0.00')
        ->and(Invoice::amount($price(100_000)))->toBe('1,000.00')
        ->and(Invoice::amount($price(123_456)))->toBe('1,234.56')
        ->and(Invoice::amount($price(-500)))->toBe('-5.00');
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

it('shows the EU size only when some line has one', function (): void {
    $with = invoicePage($this, invoiceOrder($this->member, [['Black', '9', '43', 1]]));
    $without = invoicePage($this, invoiceOrder($this->member, [['Black', '9', null, 1]]));

    expect($with)->toContain('>EU Size<')->toContain('data-line-eu>43')
        ->and($without)->not->toContain('>EU Size<')->not->toContain('data-line-eu')
        ->and($without)->toContain('data-line-size>9');
});

it('puts the UK Size column before the EU Size column, and shows it even when a line has only an EU size', function (): void {
    // An imported line has no UK size of its own: the column is still there, empty, so the two sit side by side.
    $order = invoiceOrder($this->member, [['Black', '9', '43', 1]]);
    $order->lines()->where('type', 'physical')->get()->each(function ($line): void {
        $meta = (array) $line->meta;
        unset($meta['size'], $meta['size_is_uk']);
        $line->update(['meta' => $meta]);
    });

    $html = invoicePage($this, $order);

    preg_match_all('#<th scope="col"[^>]*>\s*(.*?)\s*</th>#s', $html, $headings);

    expect($headings[1])->toContain('UK Size')->toContain('EU Size')
        ->and(array_search('UK Size', $headings[1], true))->toBe(array_search('EU Size', $headings[1], true) - 1)
        ->and($html)->toContain('data-line-size>')->toContain('data-line-eu>43');
});

it('aligns the headings with their figures: VAT and Amount on the right, Quantity and Unit Price on the left', function (): void {
    $html = invoicePage($this, invoiceOrder($this->member, [['Light grey', '9', '43', 2]]));

    preg_match('#<thead.*?</thead>#s', $html, $head);
    preg_match_all('#<th scope="col" class="([^"]*)"[^>]*>\s*(.*?)\s*</th>#s', $head[0], $headings, PREG_SET_ORDER);

    $alignment = [];

    foreach ($headings as $heading) {
        $alignment[$heading[2]] = str_contains($heading[1], 'text-right') ? 'right' : (str_contains($heading[1], 'text-left') ? 'left' : 'default');
    }

    expect($alignment['Quantity'])->toBe('left')
        ->and($alignment['Unit Price'])->toBe('left')
        ->and($alignment['VAT'])->toBe('right')
        ->and($alignment['Amount'])->toBe('right');

    // The figures under them are aligned the same way.
    preg_match('#<td class="([^"]*)" data-line-quantity>#', $html, $quantity);
    preg_match('#<td class="([^"]*)" data-line-unit-price>#', $html, $unit);

    expect($quantity[1])->toContain('text-left')->not->toContain('text-right')
        ->and($unit[1])->toContain('text-left')->not->toContain('text-right');
});

it('fills the EU size cell for the lines that have one and leaves it empty for the rest', function (): void {
    $html = invoicePage($this, invoiceOrder($this->member, [['Black', '9', '43', 1], ['Black', '8', null, 1]]));

    preg_match_all('#<td[^>]*data-line-eu>(.*?)</td>#s', $html, $cells);

    expect($cells[1])->toBe(['43', '']);
});

it('borders the lines table like the sample: grey header with a thick black rule, thin grey rule under each line, no box', function (): void {
    $html = invoicePage($this, invoiceOrder($this->member, [['Light grey', '9', '43', 2], ['Light grey', '8', '42', 3]]));

    preg_match('#<table[^>]*data-invoice-lines[^>]*>#', $html, $table);
    preg_match('#<thead[^>]*>#', $html, $head);
    preg_match_all('#<tr class="([^"]*)" data-invoice-line>#', $html, $lines);
    preg_match('#<tr[^>]*data-invoice-subtotal[^>]*>#', $html, $subtotal);
    preg_match('#<tr[^>]*data-invoice-grand[^>]*>#', $html, $grand);

    expect($table[0])->not->toContain('border')
        // Light grey header, bold, with a thick black rule under it.
        ->and($head[0])->toContain('bg-surface-muted')->toContain('font-bold')->toContain('border-b-[3px]')->toContain('border-ink')
        // A thin grey rule under every line, the last one too.
        ->and($lines[1])->toHaveCount(2)
        ->and($lines[1][0])->toContain('border-b border-border')
        ->and($lines[1][1])->toContain('border-b border-border')
        // The totals have no rules between them, and a thick black rule above TOTAL.
        ->and($subtotal[0])->not->toContain('border')
        ->and($grand[0])->toContain('border-t-[3px]')->toContain('border-ink')->toContain('font-bold');
});

it('carries the totals on as rows of the lines table', function (): void {
    $order = invoiceOrder($this->member, [['Light grey', '9', '43', 5]]);
    $html = invoicePage($this, $order);

    preg_match('#<table[^>]*data-invoice-lines.*?</table>#s', $html, $table);
    preg_match('#<thead.*?</thead>#s', $table[0], $head);
    preg_match('#<tbody[^>]*data-invoice-totals[^>]*>.*?</tbody>#s', $table[0], $totals);
    preg_match_all('#<th scope="col"#', $head[0], $columns);
    preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $totals[0], $rows);

    $columnCount = count($columns[0]);

    // Inside the table, not a separate block under it.
    expect($totals)->not->toBeEmpty()
        ->and($html)->not->toMatch('#<dl[^>]*totals#')
        // Four rows (subtotal, delivery, VAT, total, no discount): each label spans every column but the last, which is Amount.
        ->and($rows[1])->toHaveCount(4);

    foreach ($rows[1] as $row) {
        expect($row)->toContain('colspan="'.($columnCount - 1).'"');
    }

    // The amounts sit in the Amount column, under the line amounts.
    expect(array_sum(array_map(fn (string $row): int => substr_count($row, '<td'), $rows[1])))->toBe(4);
});

it('spans every column but the last in the totals, whichever columns are shown', function (): void {
    // No colour or size: Description, Quantity, Unit Price, VAT and Amount.
    $html = invoicePage($this, invoiceOrder($this->member, [[null, null, null, 1]]));

    preg_match('#<tbody[^>]*data-invoice-totals[^>]*>.*?</tbody>#s', $html, $totals);

    expect($totals[0])->toContain('colspan="4"')->not->toContain('colspan="6"');
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

    expect($html)->toContain('data-line-colour>Light grey')->toContain('data-line-size>9')->toContain('data-line-eu>43')->not->toContain('>99<');
});

it('shows the code and the option text for a line with no snapshot', function (): void {
    $order = invoiceOrder($this->member);
    $line = $order->lines()->where('type', 'physical')->sole();
    $line->update(['meta' => [], 'option' => 'Light grey, 9']);

    $html = invoicePage($this, $order);

    expect($html)->toContain('Code '.$line->identifier.', Light grey, 9')
        ->not->toContain('data-line-colour');
});

it('shows Payment Method, the due date and the bank details while a 30 day account invoice is unpaid', function (): void {
    $order = invoiceOrder($this->member);
    $html = invoicePage($this, $order);
    $due = $order->placed_at->copy()->addDays(30)->format('j M Y');

    preg_match('#<div[^>]*data-invoice-bank.*?</div>#s', $html, $bank);
    $lines = array_values(array_filter(array_map(fn (string $line): string => trim(html_entity_decode($line)), explode("\n", trim(strip_tags(preg_replace('#</p>#', "</p>\n", $bank[0])))))));

    expect($html)->toContain('<span class="font-bold">Payment Method:</span> <span data-invoice-method>30 Day Account</span>')
        ->toContain('<span class="font-bold">Due Date:</span> <span data-invoice-status>'.$due.'</span>')
        // The bank details as the client worded them.
        ->and($lines)->toBe([
            'Frontline Holdings Limited',
            'Wise Bank',
            'Sort code: 23-08-01',
            'Account number: 63606297',
            'IBAN: GB90 TRWI 2308 0163 6062 97',
            "Wise's address: 56 Shoreditch High Street, London, E1 6JJ, United Kingdom",
        ])
        // A rule above the payment section, and no payment advice slip.
        ->and($html)->toMatch('#<hr[^>]*>\s*<section[^>]*data-invoice-payment#')
        ->not->toContain('data-payment-advice')
        ->not->toContain('Payment advice');
});

it('prints TBC for a bank detail that is not set', function (): void {
    config()->set('store.bank', ['account_name' => 'Test Holdings Limited']);

    expect(Store::bankDetails())->toBe([
        'Account name' => 'Test Holdings Limited',
        'Bank' => 'TBC',
        'Sort code' => 'TBC',
        'Account number' => 'TBC',
        'IBAN' => 'TBC',
        "Wise's address" => 'TBC',
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

it('shows a paid account invoice with Status: Paid and no bank details', function (): void {
    $order = invoiceOrder($this->member);
    $order->update(['status' => 'payment-received']);

    $html = invoicePage($this, $order);

    expect($html)->toContain('<span data-invoice-method>30 Day Account</span>')
        ->toContain('<span class="font-bold">Status:</span> <span data-invoice-status>Paid</span>')
        ->not->toContain('Due Date')
        ->not->toContain('data-invoice-bank');
});

it('shows a credit card invoice as Payment Method: Credit Card and Status: Paid, with no bank details', function (): void {
    $order = invoiceOrder($this->member);
    $order->update(['meta' => array_merge((array) $order->meta, ['payment_method' => 'card'])]);

    $html = invoicePage($this, $order);

    expect($html)->toContain('<span class="font-bold">Payment Method:</span> <span data-invoice-method>Credit Card</span>')
        ->toContain('<span class="font-bold">Status:</span> <span data-invoice-status>Paid</span>')
        ->not->toContain('Due Date')
        ->not->toContain('data-invoice-bank')
        ->not->toContain('data-payment-advice');
});

it('ends with the footer as the client worded it', function (): void {
    $html = invoicePage($this, invoiceOrder($this->member));

    preg_match('#<footer[^>]*data-invoice-footer[^>]*>(.*?)</footer>#s', $html, $footer);

    expect(trim(preg_replace('/\s+/', ' ', $footer[1])))->toBe('Company Registration No: 13184904. Registered Office: Attention: Frontline Footwear Ltd, 7 Lynnwood Court, Priestlands Place, Lymington, SO41 9GA');
});

// --- The supplier number ----------------------------------------------------------------------

it('fills the supplier number in on the checkout from the customer record', function (): void {
    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd', 'meta' => ['supplier_number' => '38858']]);
    $this->member->customers()->attach($customer);

    invoiceCart($this->member);

    Livewire::actingAs($this->member, 'web')->test(CheckoutPage::class)
        ->assertSet('supplier_number', '38858')
        ->assertSeeHtml('wire:model.blur="supplier_number"')
        ->assertSee('Supplier number');
});

it('leaves the supplier number empty when the customer has none', function (): void {
    invoiceCart($this->member);

    Livewire::actingAs($this->member, 'web')->test(CheckoutPage::class)->assertSet('supplier_number', '');
});

it('puts the supplier number on the order and the invoice, and leaves the customer record alone', function (): void {
    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd']);
    $this->member->customers()->attach($customer);

    $order = invoiceOrder($this->member, supplier: 'SUP-42');

    expect($order->meta['supplier_number'])->toBe('SUP-42')
        ->and((new Invoice($order))->supplierNumber())->toBe('SUP-42')
        ->and(invoicePage($this, $order))->toContain('Supplier Number:')->toContain('data-invoice-supplier-number>SUP-42')
        // Only staff change the customer's own number.
        ->and($customer->refresh()->meta['supplier_number'] ?? null)->toBeNull();
});

it('lets the customer change a prefilled supplier number for one order', function (): void {
    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd', 'meta' => ['supplier_number' => '38858']]);
    $this->member->customers()->attach($customer);

    $order = invoiceOrder($this->member, supplier: '99999');

    expect($order->meta['supplier_number'])->toBe('99999')
        ->and($customer->refresh()->meta['supplier_number'])->toBe('38858');
});

it('records no supplier number on an order when the field is left empty', function (): void {
    $order = invoiceOrder($this->member);

    expect($order->meta)->not->toHaveKey('supplier_number')
        ->and((new Invoice($order))->supplierNumber())->toBeNull();
});

it('keeps the payment method and other order details when the supplier number is added', function (): void {
    $order = invoiceOrder($this->member, supplier: 'SUP-1');

    expect($order->meta['payment_method'])->toBe('account-30-day')
        ->and($order->meta['buying_for_employees'])->toBeTrue();
});

it('limits the supplier number to 50 characters', function (): void {
    invoiceCart($this->member);

    Livewire::actingAs($this->member, 'web')->test(CheckoutPage::class)
        ->set('supplier_number', str_repeat('9', 51))
        ->call('placeOrder')
        ->assertHasErrors(['supplier_number']);
});

it('lets staff set and clear the supplier number on the Edit Customer page', function (): void {
    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd', 'meta' => ['industry' => 'construction']]);
    $this->member->customers()->attach($customer);

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertFormSet([CustomerSupplierNumberResourceExtension::FIELD => ''])
        ->fillForm([CustomerSupplierNumberResourceExtension::FIELD => ' 38858 '])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->refresh()->meta['supplier_number'])->toBe('38858')
        // The rest of the meta is kept.
        ->and($customer->meta['industry'])->toBe('construction');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertFormSet([CustomerSupplierNumberResourceExtension::FIELD => '38858'])
        ->fillForm([CustomerSupplierNumberResourceExtension::FIELD => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->refresh()->meta)->not->toHaveKey('supplier_number')
        ->and($customer->meta['industry'])->toBe('construction');
});

it('refuses a supplier number over 50 characters in the admin', function (): void {
    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd']);
    $this->member->customers()->attach($customer);

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm([CustomerSupplierNumberResourceExtension::FIELD => str_repeat('9', 51)])
        ->call('save')
        ->assertHasFormErrors([CustomerSupplierNumberResourceExtension::FIELD]);
});

it('leaves the supplier number alone when staff save a customer that has no login', function (): void {
    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Pat', 'last_name' => 'Walker', 'company_name' => 'Walker Ltd', 'meta' => ['supplier_number' => '777']]);

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm(['company_name' => 'Walker & Co'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->refresh()->meta['supplier_number'])->toBe('777');
});

it('only ever shows a customer their own invoice', function (): void {
    $order = invoiceOrder($this->member);
    $stranger = User::factory()->withAccountTerms()->create();

    $this->actingAs($stranger, 'web')->get(route('account.invoices.show', $order->reference))->assertNotFound();
});

it('uses UK English and no em dashes in its copy', function (): void {
    foreach (['resources/views/account/invoices/show.blade.php', 'app/Domain/Orders/InvoiceNumbers.php', 'app/Domain/Orders/Invoice.php', 'app/Domain/Orders/SnapshotLineOptions.php'] as $file) {
        $text = (string) file_get_contents(base_path($file));

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(organization|license|favorite|analyze)\b/i');
    }
});
