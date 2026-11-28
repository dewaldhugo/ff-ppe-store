<?php

declare(strict_types=1);

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Widgets\RecentOrdersWidget;
use App\Domain\Orders\Invoice;
use App\Domain\Orders\InvoiceNumbers;
use App\Domain\Orders\InvoicePdf;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder;
use Lunar\Admin\Models\Staff;
use Lunar\Base\ValueObjects\Cart\TaxBreakdown;
use Lunar\Base\ValueObjects\Cart\TaxBreakdownAmount;
use Lunar\DataTypes\Price;
use Lunar\Models\Channel;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

// Staff see invoices and download them as PDFs: the Invoices list under Sales, the Invoice PDF button on the order
// page and on the dashboard's latest orders (App\Admin\Resources\InvoiceResource, App\Domain\Orders\InvoicePdf).

beforeEach(function (): void {
    config()->set('store.noindex', true);
    Mail::fake();
    Notification::fake();
    Carbon::setTestNow('2026-03-15 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A placed order with one goods line, a bill to and a deliver to, numbered the way the store numbers it. Account
 * terms and Payment Received by default (paid); pass $attributes to change it.
 */
function adminInvoiceOrder(array $attributes = [], bool $numbered = true): Order
{
    $customer = Customer::query()->create(['first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => $attributes['company'] ?? 'Builder Ltd']);
    unset($attributes['company']);

    $order = Order::withoutEvents(fn (): Order => Order::factory()->create([
        'channel_id' => Channel::getDefault()->id,
        'customer_id' => $customer->id,
        'status' => 'payment-received',
        'sub_total' => 10000,
        'tax_total' => 2000,
        'total' => 12000,
        'placed_at' => '2026-03-10 09:00:00',
        'meta' => ['payment_method' => 'account-30-day'],
        ...$attributes,
    ]));

    OrderLine::factory()->create([
        'order_id' => $order->id,
        'purchasable_type' => null,
        'purchasable_id' => null,
        'type' => 'physical',
        'description' => 'CADOR',
        'option' => 'Green, EU 41',
        'identifier' => 'SJ-1713342',
        'unit_price' => 5000,
        'quantity' => 2,
        'sub_total' => 10000,
        'tax_total' => 2000,
        'total' => 12000,
        'discount_total' => 0,
        // 20% VAT on the line, as a real order has it.
        'tax_breakdown' => new TaxBreakdown(collect([new TaxBreakdownAmount(price: new Price(2000, Currency::getDefault(), 1), identifier: 'tax_rate_1', description: 'VAT', percentage: 20.0)])),
        'meta' => ['colour' => 'Green', 'eu_size' => '41'],
    ]);

    // Lunar's order page shows the country of each address, so each has one.
    $country = Country::query()->first() ?? Country::factory()->create();

    foreach (['billing', 'shipping'] as $type) {
        $order->addresses()->create([
            'type' => $type, 'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd',
            'line_one' => '1 High Street', 'city' => 'Bromley', 'postcode' => 'BR5 3QX', 'country_id' => $country->id, 'contact_email' => 'jo@builder.example',
        ]);
    }

    if ($numbered && $order->placed_at !== null) {
        app(InvoiceNumbers::class)->assign($order);
    }

    return $order->fresh();
}

function invoiceStaff(bool $admin = true): Staff
{
    $staff = Staff::factory()->create(['admin' => $admin]);
    test()->actingAs($staff, 'staff');
    Filament::setCurrentPanel(Filament::getPanel('lunar'));

    return $staff;
}

// --- The PDF ----------------------------------------------------------------------------------

it('makes a real PDF of a placed order\'s invoice, named by its invoice number', function (): void {
    $order = adminInvoiceOrder();
    $pdf = app(InvoicePdf::class);

    $bytes = $pdf->render($order);

    expect($bytes)->toStartWith('%PDF-')->and(strlen($bytes))->toBeGreaterThan(2000)
        ->and($pdf->filename($order))->toBe('Invoice-10032026-001.pdf');
});

it('shows on the PDF what the invoice page shows: number, date, parties, lines, VAT, total and payment', function (): void {
    $order = adminInvoiceOrder(['customer_reference' => 'LMLLP / 29095']);

    $html = view('admin.pdf.invoice', [
        'invoice' => new Invoice($order),
        'order' => $order,
        'colours' => InvoicePdf::COLOURS,
        'logo' => public_path('images/frontline-logo.png'),
    ])->render();

    expect($html)
        ->toContain('Tax invoice')
        ->toContain('10 Mar 2026')
        ->toContain('10032026/001')
        ->toContain('LMLLP / 29095')
        ->toContain('Bill to')->toContain('Deliver to')->toContain('Builder Ltd')->toContain('1 High Street')
        ->toContain('CADOR')->toContain('SJ-1713342')->toContain('Green')->toContain('41')
        ->toContain('50.00')->toContain('100.00')
        ->toContain('TOTAL VAT 20%')->toContain('20.00')
        ->toContain('£120.00')
        ->toContain('30 Day Account')->toContain('Status:')->toContain('Paid')
        ->toContain('Company Registration No');
});

it('shows the due date and bank details on an invoice still to pay', function (): void {
    $order = adminInvoiceOrder(['status' => 'awaiting-payment']);

    $html = view('admin.pdf.invoice', [
        'invoice' => new Invoice($order),
        'order' => $order,
        'colours' => InvoicePdf::COLOURS,
        'logo' => public_path('images/frontline-logo.png'),
    ])->render();

    // 30 days after 10 March.
    expect($html)->toContain('Due Date:')->toContain('9 Apr 2026')->toContain('Sort code')->not->toContain('Status:');
});

it('has no invoice for an order that was never placed', function (): void {
    $order = adminInvoiceOrder(['placed_at' => null], numbered: false);

    expect(app(InvoicePdf::class)->available($order))->toBeFalse();
});

it('keeps the PDF\'s colours equal to the design tokens', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    $token = function (string $name) use ($css): string {
        preg_match('/--color-'.preg_quote($name, '/').':\s*(#[0-9A-Fa-f]{6})/', $css, $match);

        return strtoupper($match[1] ?? 'missing');
    };

    expect(InvoicePdf::COLOURS['bar'])->toBe($token('brand-blue'))
        ->and(InvoicePdf::COLOURS['ink'])->toBe($token('ink'))
        ->and(InvoicePdf::COLOURS['muted'])->toBe($token('ink-muted'))
        ->and(InvoicePdf::COLOURS['border'])->toBe($token('border'))
        ->and(InvoicePdf::COLOURS['head'])->toBe($token('surface-muted'));
});

it('sorts the invoice lines by product name, then colour, then size smallest first, on the PDF and the invoice page alike', function (): void {
    $order = adminInvoiceOrder();   // its own line: CADOR, Green, EU 41

    // Added out of order on purpose. The identifier says where each belongs (ID-1 first).
    foreach ([
        ['LIGERO', ['colour' => 'Navy', 'size' => '10', 'size_is_uk' => true], 'ID-8'],
        ['CADOR S3 MID', ['colour' => 'Black', 'eu_size' => '41'], 'ID-5'],
        ['CADOR', ['colour' => 'Green', 'eu_size' => '44'], 'ID-4'],
        ['LIGERO', ['colour' => 'Navy', 'size' => '6.5', 'size_is_uk' => true], 'ID-6'],
        ['CADOR', ['colour' => 'Blue', 'eu_size' => '43'], 'ID-1'],
        ['LIGERO', ['colour' => 'Navy', 'size' => '9', 'size_is_uk' => true], 'ID-7'],
        ['CADOR', ['colour' => 'Green', 'eu_size' => '38'], 'ID-2'],
    ] as [$description, $meta, $identifier]) {
        OrderLine::factory()->create([
            'order_id' => $order->id, 'purchasable_type' => null, 'purchasable_id' => null, 'type' => 'physical',
            'description' => $description, 'identifier' => $identifier, 'quantity' => 1, 'meta' => $meta,
        ]);
    }

    $order->lines()->where('identifier', 'SJ-1713342')->update(['identifier' => 'ID-3']);
    $invoice = new Invoice($order->fresh(['lines']));

    // Product name first (CADOR before CADOR S3 MID before LIGERO), then colour, then size: EU sizes 38, 41, 44, and 6.5, 9, 10 as numbers.
    expect($invoice->goodsLines()->pluck('identifier')->all())->toBe(['ID-1', 'ID-2', 'ID-3', 'ID-4', 'ID-5', 'ID-6', 'ID-7', 'ID-8']);

    $html = view('admin.pdf.invoice', [
        'invoice' => $invoice, 'order' => $invoice->order, 'colours' => InvoicePdf::COLOURS, 'logo' => public_path('images/frontline-logo.png'),
    ])->render();

    preg_match_all('/Code (ID-\d)/', $html, $codes);

    expect($codes[1])->toBe(['ID-1', 'ID-2', 'ID-3', 'ID-4', 'ID-5', 'ID-6', 'ID-7', 'ID-8']);
});

// --- The Invoices list ------------------------------------------------------------------------

it('lists placed orders as invoices with number, date, company, order number, PO and total', function (): void {
    invoiceStaff();
    $a = adminInvoiceOrder(['reference' => 'ORD-A', 'placed_at' => '2026-03-01 10:00:00', 'customer_reference' => 'PO-1', 'total' => 140964]);
    $b = adminInvoiceOrder(['reference' => 'ORD-B', 'placed_at' => '2026-03-05 10:00:00']);
    $draft = adminInvoiceOrder(['reference' => 'ORD-DRAFT', 'placed_at' => null], numbered: false);

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$b, $a], inOrder: true)
        ->assertCanNotSeeTableRecords([$draft])
        ->assertSee('01032026/001')
        ->assertSee('05032026/001')
        ->assertSee('Builder Ltd')
        ->assertSee('ORD-A')
        ->assertSee('PO-1')
        ->assertSee('£1,409.64');
});

it('still lists a placed order whose invoice number has not been given yet', function (): void {
    invoiceStaff();
    $order = adminInvoiceOrder(['reference' => 'ORD-NONUM'], numbered: false);

    Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$order]);
});

it('searches invoices by invoice number, company, order number and PO number', function (): void {
    invoiceStaff();
    $one = adminInvoiceOrder(['reference' => 'ORD-ONE', 'placed_at' => '2026-03-01 10:00:00', 'company' => 'Builder Ltd', 'customer_reference' => 'PO-111']);
    $two = adminInvoiceOrder(['reference' => 'ORD-TWO', 'placed_at' => '2026-03-02 10:00:00', 'company' => 'Roofer Ltd', 'customer_reference' => 'PO-222']);

    Livewire::test(ListInvoices::class)
        ->searchTable('02032026')->assertCanSeeTableRecords([$two])->assertCanNotSeeTableRecords([$one])
        ->searchTable('Roofer')->assertCanSeeTableRecords([$two])->assertCanNotSeeTableRecords([$one])
        ->searchTable('ORD-ONE')->assertCanSeeTableRecords([$one])->assertCanNotSeeTableRecords([$two])
        ->searchTable('PO-222')->assertCanSeeTableRecords([$two])->assertCanNotSeeTableRecords([$one]);
});

it('filters invoices by payment: paid, not yet paid and overdue', function (): void {
    invoiceStaff();

    $paid = adminInvoiceOrder(['reference' => 'ORD-PAID', 'placed_at' => '2026-03-01 10:00:00']);
    $card = adminInvoiceOrder(['reference' => 'ORD-CARD', 'placed_at' => '2026-03-02 10:00:00', 'status' => 'payment-received', 'meta' => ['payment_method' => 'card']]);
    $due = adminInvoiceOrder(['reference' => 'ORD-DUE', 'placed_at' => '2026-03-08 10:00:00', 'status' => 'awaiting-payment']);        // 7 days old: not yet due
    $overdue = adminInvoiceOrder(['reference' => 'ORD-LATE', 'placed_at' => '2026-01-20 10:00:00', 'status' => 'awaiting-payment']);  // 54 days old

    Livewire::test(ListInvoices::class)
        ->filterTable('payment', 'paid')->assertCanSeeTableRecords([$paid, $card])->assertCanNotSeeTableRecords([$due, $overdue])
        ->filterTable('payment', 'unpaid')->assertCanSeeTableRecords([$due, $overdue])->assertCanNotSeeTableRecords([$paid, $card])
        ->filterTable('payment', 'overdue')->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$paid, $card, $due]);
});

it('shows each invoice\'s payment state as Paid, due by a date, or overdue', function (): void {
    invoiceStaff();
    adminInvoiceOrder(['reference' => 'ORD-PAID']);
    adminInvoiceOrder(['reference' => 'ORD-DUE', 'placed_at' => '2026-03-08 10:00:00', 'status' => 'awaiting-payment']);
    adminInvoiceOrder(['reference' => 'ORD-LATE', 'placed_at' => '2026-01-20 10:00:00', 'status' => 'awaiting-payment']);

    Livewire::test(ListInvoices::class)
        ->assertSee('Paid')
        ->assertSee('Payment due by 7 April 2026')
        ->assertSee('Overdue, was due by 19 February 2026');
});

it('filters invoices by date', function (): void {
    invoiceStaff();
    $early = adminInvoiceOrder(['reference' => 'ORD-EARLY', 'placed_at' => '2026-02-01 10:00:00']);
    $late = adminInvoiceOrder(['reference' => 'ORD-LATE2', 'placed_at' => '2026-03-10 10:00:00']);

    Livewire::test(ListInvoices::class)
        ->filterTable('date', ['from' => '2026-03-01', 'until' => null])->assertCanSeeTableRecords([$late])->assertCanNotSeeTableRecords([$early])
        ->filterTable('date', ['from' => null, 'until' => '2026-02-28'])->assertCanSeeTableRecords([$early])->assertCanNotSeeTableRecords([$late]);
});

it('is for staff who can manage orders, is read-only and sits under Sales', function (): void {
    $limited = invoiceStaff(admin: false);

    // A staff member without the orders permission does not see it.
    expect(InvoiceResource::canViewAny())->toBeFalse();

    invoiceStaff(admin: true);

    expect(InvoiceResource::canViewAny())->toBeTrue()
        ->and(InvoiceResource::canCreate())->toBeFalse()
        ->and(InvoiceResource::canEdit(adminInvoiceOrder()))->toBeFalse()
        ->and(InvoiceResource::canDelete(adminInvoiceOrder()))->toBeFalse()
        ->and(InvoiceResource::getNavigationGroup())->toBe(__('lunarpanel::global.sections.sales'))
        ->and(array_keys(InvoiceResource::getPages()))->toBe(['index']);
});

// --- Downloading ------------------------------------------------------------------------------

it('downloads the invoice PDF from a row of the Invoices list', function (): void {
    invoiceStaff();
    $order = adminInvoiceOrder();

    Livewire::test(ListInvoices::class)
        ->callTableAction('download_invoice_pdf', $order)
        ->assertFileDownloaded('Invoice-10032026-001.pdf');
});

it('downloads the invoice PDF from the order page', function (): void {
    invoiceStaff();
    $order = adminInvoiceOrder();

    Livewire::test(ManageOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('download_invoice_pdf')
        ->callAction('download_invoice_pdf')
        ->assertFileDownloaded('Invoice-10032026-001.pdf');
});

it('has no Invoice PDF button on an order that was never placed', function (): void {
    invoiceStaff();
    $draft = adminInvoiceOrder(['placed_at' => null], numbered: false);

    Livewire::test(ManageOrder::class, ['record' => $draft->getRouteKey()])->assertActionHidden('download_invoice_pdf');
});

it('downloads the invoice PDF from the dashboard\'s latest orders', function (): void {
    invoiceStaff();
    $order = adminInvoiceOrder();

    Livewire::test(RecentOrdersWidget::class)
        ->callTableAction('download_invoice_pdf', $order)
        ->assertFileDownloaded('Invoice-10032026-001.pdf');
});

it('gives an order without a number one when its PDF is made, and never a second', function (): void {
    $order = adminInvoiceOrder(numbered: false);

    expect(DB::table('invoice_numbers')->where('order_id', $order->id)->exists())->toBeFalse();

    $name = app(InvoicePdf::class)->filename($order);

    expect($name)->toBe('Invoice-10032026-001.pdf')
        ->and(DB::table('invoice_numbers')->where('order_id', $order->id)->count())->toBe(1)
        ->and(app(InvoicePdf::class)->filename($order))->toBe($name);
});
