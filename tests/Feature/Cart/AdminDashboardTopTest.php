<?php

declare(strict_types=1);

use App\Admin\Widgets\RecentOrdersWidget;
use App\Admin\Widgets\SalesTilesWidget;
use App\Domain\Orders\DashboardFigures;
use App\Domain\Orders\ZarRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Lunar\Admin\Filament\Pages\Dashboard;
use Lunar\Admin\Filament\Widgets\Dashboard\Orders\LatestOrdersTable;
use Lunar\Admin\Filament\Widgets\Dashboard\Orders\OrderStatsOverview;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Channel;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

// The sales tiles and the latest orders list at the top of the admin dashboard, above Lunar's own widgets
// (App\Admin\Widgets, App\Domain\Orders\DashboardFigures).

beforeEach(function (): void {
    config()->set('store.noindex', true);
    Carbon::setTestNow('2026-03-15 12:00:00');

    // The rand rate comes from an outside API (ZarRate): no test reaches it. A test that needs a rate fakes the answer; any
    // other request fails, which gives no rand figure.
    Cache::forget(ZarRate::CACHE_KEY);
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

function dashboardOrder(array $attributes = [], array $lines = []): Order
{
    // Without events: the store's own hooks would number the order and email staff. These tests number the ones they need.
    $order = Order::withoutEvents(fn (): Order => Order::factory()->create([
        'channel_id' => Channel::getDefault()->id,
        'status' => 'payment-received',
        'sub_total' => 10000,
        'tax_total' => 2000,
        'total' => 12000,
        'placed_at' => '2026-03-10 09:00:00',
        ...$attributes,
    ]));

    foreach ($lines as [$description, $quantity, $type]) {
        OrderLine::factory()->create([
            'order_id' => $order->id,
            'purchasable_type' => null,
            'purchasable_id' => null,
            'type' => $type ?? 'physical',
            'description' => $description,
            'quantity' => $quantity,
        ]);
    }

    return $order;
}

function dashboardStaff(): Staff
{
    $staff = Staff::factory()->create(['admin' => true]);
    test()->actingAs($staff, 'staff');

    return $staff;
}

// --- The figures ------------------------------------------------------------------------------

it('counts placed orders that did not fail or get cancelled, and nothing else', function (): void {
    dashboardOrder(['total' => 12000]);
    dashboardOrder(['total' => 6000, 'status' => 'awaiting-payment']);          // invoiced on account: counts
    dashboardOrder(['total' => 99900, 'status' => 'cancelled']);                // does not
    dashboardOrder(['total' => 88800, 'status' => 'failed']);                   // does not
    dashboardOrder(['total' => 77700, 'placed_at' => null]);                    // never placed: does not

    $figures = new DashboardFigures;

    expect($figures->orderCount())->toBe(2)
        ->and($figures->income())->toBe('£180.00');
});

it('adds up the pairs sold from goods lines only, and names the best seller', function (): void {
    dashboardOrder(lines: [['CADOR', 10, 'physical'], ['MODULO', 3, 'physical'], ['Freight', 1, 'shipping']]);
    dashboardOrder(lines: [['MODULO', 9, 'physical']]);
    dashboardOrder(['status' => 'cancelled'], [['CADOR', 50, 'physical']]);

    $figures = new DashboardFigures;

    // CADOR 10, MODULO 3 + 9 = 12; the cancelled order's 50 pairs and the freight line count for nothing.
    expect($figures->unitsSold())->toBe(22)
        ->and($figures->bestSeller())->toBe('MODULO');
});

it('has no best seller before the first sale', function (): void {
    expect((new DashboardFigures)->bestSeller())->toBeNull()
        ->and((new DashboardFigures)->lastOrderDate())->toBeNull()
        ->and((new DashboardFigures)->income())->toBe('£0.00');
});

it('counts this month\'s orders, names the day of the last order and counts the clients', function (): void {
    dashboardOrder(['placed_at' => '2026-03-02 10:00:00']);
    dashboardOrder(['placed_at' => '2026-03-12 16:30:00']);
    dashboardOrder(['placed_at' => '2026-02-27 10:00:00']);
    Customer::query()->create(['first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd']);
    Customer::query()->create(['first_name' => 'Al', 'last_name' => 'Roofer', 'company_name' => 'Roofer Ltd']);

    $figures = new DashboardFigures;

    expect($figures->ordersThisMonth())->toBe(2)
        ->and($figures->lastOrderDate())->toBe('12 Mar')
        ->and($figures->clients())->toBe(2);

    // An order from an earlier year names its year.
    Order::query()->delete();
    dashboardOrder(['placed_at' => '2025-12-30 10:00:00']);

    expect((new DashboardFigures)->lastOrderDate())->toBe('30 Dec 2025');
});

it('averages this year\'s turnover before VAT over the months so far, to the penny', function (): void {
    // It is 15 March: three months. £300.00 + £600.00 + £300.01 ex VAT this year, £5,000.00 last year (ignored).
    dashboardOrder(['placed_at' => '2026-01-20 10:00:00', 'sub_total' => 30000, 'total' => 36000]);
    dashboardOrder(['placed_at' => '2026-02-20 10:00:00', 'sub_total' => 60000, 'total' => 72000]);
    dashboardOrder(['placed_at' => '2026-03-10 10:00:00', 'sub_total' => 30001, 'total' => 36001]);
    dashboardOrder(['placed_at' => '2025-11-10 10:00:00', 'sub_total' => 500000, 'total' => 600000]);

    // 120001 / 3 = 40000.33 pence, so £400.00.
    expect((new DashboardFigures)->averageMonthlyTurnover())->toBe('£400.00');

    Order::query()->delete();
    dashboardOrder(['placed_at' => '2026-03-10 10:00:00', 'sub_total' => 30002, 'total' => 36002]);

    // 30002 / 3 = 10000.67 pence rounds up to £100.01.
    expect((new DashboardFigures)->averageMonthlyTurnover())->toBe('£100.01');
});

// --- The dashboard ----------------------------------------------------------------------------

it('puts the tiles and the latest orders above Lunar\'s own widgets, which stay', function (): void {
    dashboardStaff();

    $widgets = (new Dashboard)->getWidgets();

    expect($widgets[0])->toBe(SalesTilesWidget::class)
        ->and($widgets[1])->toBe(RecentOrdersWidget::class)
        ->and($widgets)->toContain(OrderStatsOverview::class)
        ->and($widgets)->toContain(LatestOrdersTable::class);
});

it('registers both widgets with the panel, so Livewire can find them when the page loads them', function (): void {
    $registry = app(ComponentRegistry::class);

    foreach ([SalesTilesWidget::class, RecentOrdersWidget::class] as $widget) {
        expect($registry->getClass($registry->getName($widget)))->toBe($widget);
    }
});

function profitLine(Order $order, int $subTotal, int $quantity, array $meta = []): void
{
    OrderLine::factory()->create([
        'order_id' => $order->id,
        'purchasable_type' => null,
        'purchasable_id' => null,
        'type' => 'physical',
        'quantity' => $quantity,
        'unit_price' => intdiv($subTotal, $quantity),
        'sub_total' => $subTotal,
        'total' => $subTotal * 1.2,
        'meta' => $meta,
    ]);
}

it('takes the vendor cost off this month\'s sales before VAT to give the gross profit', function (): void {
    // 3 pairs sold at £15.00 each ex VAT (£45.00) that cost £10.00 each: £15.00 profit.
    profitLine(dashboardOrder(), 4500, 3, ['cost_price' => 1000]);
    // A second order this month: £30.00 sold, £20.00 cost.
    profitLine(dashboardOrder(['placed_at' => '2026-03-01 08:00:00']), 3000, 1, ['cost_price' => 2000]);

    expect((new DashboardFigures)->grossProfitThisMonthPence())->toBe(1500 + 1000)
        ->and((new DashboardFigures)->grossProfitThisMonth())->toBe('£25.00');
});

it('uses the cost recorded when the order was placed, not the vendor\'s cost today', function (): void {
    config()->set('vendorsync.markup', '3');
    profitLine(dashboardOrder(), 3000, 1, ['vendor_code' => 'SJ', 'cost_price' => 2000]);

    expect((new DashboardFigures)->grossProfitThisMonthPence())->toBe(1000);
});

it('takes the markup off the price when a line has no recorded cost, with the vendor\'s own markup first', function (): void {
    // Default markup 1.5: £30.00 / 1.5 = £20.00 cost, £10.00 profit.
    config()->set('vendorsync.markup', '1.5');
    config()->set('vendorsync.vendors.SJ.markup', null);
    profitLine(dashboardOrder(), 3000, 1);

    expect((new DashboardFigures)->grossProfitThisMonthPence())->toBe(1000);

    // The vendor's own markup of 2: £30.00 / 2 = £15.00 cost, £15.00 profit.
    config()->set('vendorsync.vendors.FS.markup', '2');
    profitLine(dashboardOrder(), 3000, 1, ['vendor_code' => 'FS', 'cost_price' => null]);

    expect((new DashboardFigures)->grossProfitThisMonthPence())->toBe(1000 + 1500);
});

it('leaves out delivery lines, other months and orders that failed, were cancelled or were never placed', function (): void {
    profitLine(dashboardOrder(), 3000, 1, ['cost_price' => 2000]);                                      // counts: 1000
    OrderLine::factory()->create(['order_id' => dashboardOrder()->id, 'purchasable_type' => null, 'purchasable_id' => null, 'type' => 'shipping', 'quantity' => 1, 'sub_total' => 800]);
    profitLine(dashboardOrder(['placed_at' => '2026-02-28 23:00:00']), 9000, 1, ['cost_price' => 1]);  // last month
    profitLine(dashboardOrder(['placed_at' => '2026-04-01 00:30:00']), 9000, 1, ['cost_price' => 1]);  // next month
    profitLine(dashboardOrder(['status' => 'cancelled']), 9000, 1, ['cost_price' => 1]);
    profitLine(dashboardOrder(['status' => 'failed']), 9000, 1, ['cost_price' => 1]);
    profitLine(dashboardOrder(['placed_at' => null]), 9000, 1, ['cost_price' => 1]);

    expect((new DashboardFigures)->grossProfitThisMonthPence())->toBe(1000);
});

it('shows a month with no sales as £0.00 profit', function (): void {
    expect((new DashboardFigures)->grossProfitThisMonth())->toBe('£0.00');
});

it('shows the gross profit tile on the dashboard', function (): void {
    dashboardStaff();
    profitLine(dashboardOrder(), 4500, 3, ['cost_price' => 1000]);

    Livewire::test(SalesTilesWidget::class)
        ->assertSee('Gross profit this month')
        ->assertSee('£15.00');
});

it('shows the nine tiles with their figures', function (): void {
    dashboardStaff();
    dashboardOrder(['total' => 12000], [['CADOR S1P LOW', 7, 'physical']]);
    Customer::query()->create(['first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd']);

    Livewire::test(SalesTilesWidget::class)
        ->assertSee('Total income (inc VAT)')->assertSee('£120.00')
        ->assertSee('Orders to date')
        ->assertSee('Total units sold')
        ->assertSee('Best seller')->assertSee('CADOR S1P LOW')
        ->assertSee('Orders this month')
        ->assertSee('Last order placed')->assertSee('10 Mar')
        ->assertSee('Clients')
        ->assertSee('Average turnover per month this year (ex VAT)');
});

it('colours the tiles green, red, blue and orange down the columns, the same every time', function (): void {
    dashboardStaff();

    $html = Livewire::test(SalesTilesWidget::class)->html();

    // The gross profit tile leads, light blue, on a row of its own; then eight tiles: each colour twice in column order, first row then second.
    preg_match_all('/sales-tile--(success|danger|info|warning)/', $html, $colours);

    expect($colours[1])->toBe(['success', 'danger', 'info', 'warning', 'success', 'danger', 'info', 'warning'])
        ->and(substr_count($html, 'sales-tile--sky'))->toBe(1)
        ->and(substr_count($html, 'sales-tile--wide'))->toBe(1)
        ->and(strpos($html, 'sales-tile--sky'))->toBeLessThan(strpos($html, 'sales-tile--success'))
        ->and(strpos($html, 'Gross profit this month'))->toBeLessThan(strpos($html, 'Total income (inc VAT)'));

    // The colours are defined in the admin styles, from Filament's own variables (no colour of our own).
    $styles = (string) file_get_contents(resource_path('views/admin/dashboard-tiles.blade.php'));

    foreach (['success', 'danger', 'info', 'warning'] as $colour) {
        expect($styles)->toContain('.sales-tile--'.$colour)->toContain('var(--'.$colour.'-700)');
    }
});

it('lists placed orders newest first with company, date, order number, invoice number and value', function (): void {
    dashboardStaff();
    $customer = Customer::query()->create(['first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd']);

    $older = dashboardOrder(['customer_id' => $customer->id, 'reference' => 'ORD-OLDER', 'placed_at' => '2026-02-01 10:00:00', 'total' => 12000]);
    $newer = dashboardOrder(['customer_id' => $customer->id, 'reference' => 'ORD-NEWER', 'placed_at' => '2026-03-01 10:00:00', 'total' => 140964]);
    $unplaced = dashboardOrder(['customer_id' => $customer->id, 'reference' => 'ORD-DRAFT', 'placed_at' => null]);

    DB::table('invoice_numbers')->insert(['order_id' => $newer->id, 'invoice_date' => '2026-03-01', 'sequence' => 1, 'number' => '01032026/001', 'created_at' => now()]);

    Livewire::test(RecentOrdersWidget::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertCanNotSeeTableRecords([$unplaced])
        ->assertSee('Builder Ltd')
        ->assertSee('2026-03-01')
        ->assertSee('ORD-NEWER')
        ->assertSee('01032026/001')
        ->assertSee('£1,409.64');
});

it('searches the latest orders by company, order number and invoice number', function (): void {
    dashboardStaff();
    $builder = Customer::query()->create(['first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd']);
    $roofer = Customer::query()->create(['first_name' => 'Al', 'last_name' => 'Roofer', 'company_name' => 'Roofer Ltd']);

    $one = dashboardOrder(['customer_id' => $builder->id, 'reference' => 'ORD-ONE']);
    $two = dashboardOrder(['customer_id' => $roofer->id, 'reference' => 'ORD-TWO']);
    DB::table('invoice_numbers')->insert(['order_id' => $two->id, 'invoice_date' => '2026-03-10', 'sequence' => 1, 'number' => '10032026/001', 'created_at' => now()]);

    Livewire::test(RecentOrdersWidget::class)
        ->searchTable('Roofer')->assertCanSeeTableRecords([$two])->assertCanNotSeeTableRecords([$one])
        ->searchTable('ORD-ONE')->assertCanSeeTableRecords([$one])->assertCanNotSeeTableRecords([$two])
        ->searchTable('10032026')->assertCanSeeTableRecords([$two])->assertCanNotSeeTableRecords([$one]);
});

it('pages the latest orders ten at a time', function (): void {
    dashboardStaff();

    foreach (range(1, 12) as $day) {
        dashboardOrder(['placed_at' => '2026-02-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 10:00:00']);
    }

    $table = Livewire::test(RecentOrdersWidget::class);

    // Twelve orders in all, ten on the first page.
    expect($table->instance()->getAllTableRecordsCount())->toBe(12)
        ->and($table->instance()->getTableRecords())->toHaveCount(10);
});

// --- The rand value beside the pounds --------------------------------------------------------

/** The Frankfurter API answering with this many rand to the pound. */
function zarApiReturns(float $rate): void
{
    Http::fake(['api.frankfurter.dev/*' => Http::response(['amount' => 1.0, 'base' => 'GBP', 'date' => '2026-11-27', 'rates' => ['ZAR' => $rate]])]);
}

it('asks the Frankfurter API for rand to the pound, sending nothing else', function (): void {
    zarApiReturns(22.747);

    expect(app(ZarRate::class)->perPound())->toBe(22.747);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://api.frankfurter.dev/v1/latest')
        && $request['base'] === 'GBP' && $request['symbols'] === 'ZAR'
        && ! str_contains($request->url(), '@'));
});

it('keeps a good rate for the next calls instead of asking again', function (): void {
    zarApiReturns(22.747);

    app(ZarRate::class)->perPound();
    app(ZarRate::class)->perPound();
    (new DashboardFigures)->zar(100000);

    Http::assertSentCount(1);
});

it('has no rate when the API fails, and does not ask again for a few minutes', function (mixed $answer): void {
    Http::fake(['api.frankfurter.dev/*' => $answer]);

    expect(app(ZarRate::class)->perPound())->toBeNull()
        ->and(app(ZarRate::class)->perPound())->toBeNull()
        ->and((new DashboardFigures)->zar(100000))->toBeNull();

    Http::assertSentCount(1);

    // After the five minutes it asks again, and a good answer then gives the rate.
    $this->travel(6)->minutes();
    zarApiReturns(22.5);

    expect(app(ZarRate::class)->perPound())->toBe(22.5);
})->with([
    'a server error' => fn () => Http::response('', 500),
    'not JSON' => fn () => Http::response('<html>down</html>', 200),
    'no ZAR in the answer' => fn () => Http::response(['rates' => ['USD' => 1.3]]),
    'a rate that is not positive' => fn () => Http::response(['rates' => ['ZAR' => 0]]),
    'a rate that is not a number' => fn () => Http::response(['rates' => ['ZAR' => 'lots']]),
    'no connection' => fn () => Http::failedConnection(),
]);

it('works out whole rand from pence at the rate, rounded half up', function (): void {
    zarApiReturns(23.4);

    $figures = new DashboardFigures;

    // £1,000.00 at R23.40 is R23,400; £1.00 at R23.40 is R23 (23.4 rounds down); £0.03 at R23.40 is R1 (0.702 rounds up).
    expect($figures->zar(100000))->toBe('R 23,400')
        ->and($figures->zar(100))->toBe('R 23')
        ->and($figures->zar(3))->toBe('R 1')
        ->and($figures->zar(0))->toBe('R 0')
        ->and($figures->zarRate())->toBe('23.4');

    // Half a rand rounds up: £0.50 at R23.00 is R11.50.
    Cache::forget(ZarRate::CACHE_KEY);
    zarApiReturns(23.0);

    expect($figures->zar(50))->toBe('R 12');
});

it('shows a small muted rand value beside the pounds on the three money tiles only', function (): void {
    zarApiReturns(20.0);
    dashboardStaff();
    profitLine(dashboardOrder(['total' => 12000, 'sub_total' => 10000]), 4500, 3, ['cost_price' => 1000]);

    $html = Livewire::test(SalesTilesWidget::class)->html();

    // Total income £120.00 = R2,400; average turnover £100.00 over three months of the year so far, all in March: £33.33 = R667;
    // gross profit this month £15.00 = R300. Three tiles, no more.
    expect(substr_count($html, 'sales-tile-zar'))->toBe(3)
        ->and($html)->toContain('R 2,400')
        ->and($html)->toContain('R 667')
        ->and($html)->toContain('R 300')
        ->and($html)->toContain('£120.00')
        ->and($html)->toContain('at R20 to the pound')
        ->and((string) file_get_contents(resource_path('views/admin/dashboard-tiles.blade.php')))->toContain('.sales-tile .sales-tile-zar');
});

it('shows the pounds alone when the rate cannot be fetched', function (): void {
    Http::fake(['api.frankfurter.dev/*' => Http::response('', 503)]);
    dashboardStaff();
    dashboardOrder(['total' => 12000]);

    Livewire::test(SalesTilesWidget::class)->assertSee('£120.00')->assertDontSee('sales-tile-zar', false)->assertDontSee('R 0');
});

it('makes the rand value 20% bigger than the 0.8rem it was', function (): void {
    expect((string) file_get_contents(resource_path('views/admin/dashboard-tiles.blade.php')))
        ->toContain('.sales-tile .sales-tile-zar { margin-left: 0.5rem; font-size: 0.96rem;');
});

// --- Gross profit on the latest orders ------------------------------------------------------

it('shows each latest order\'s gross profit in pounds, from its goods lines only', function (): void {
    dashboardStaff();

    // 3 pairs sold for £45.00 ex VAT that cost £10.00 each: £15.00. The delivery line is not goods and does not count.
    $order = dashboardOrder();
    profitLine($order, 4500, 3, ['cost_price' => 1000]);
    OrderLine::factory()->create(['order_id' => $order->id, 'purchasable_type' => null, 'purchasable_id' => null, 'type' => 'shipping', 'quantity' => 1, 'sub_total' => 800]);

    // No recorded cost: the markup comes off the price. £30.00 / 1.5 = £20.00 cost, £10.00 profit.
    config()->set('vendorsync.markup', '1.5');
    $other = dashboardOrder(['placed_at' => '2026-03-11 09:00:00']);
    profitLine($other, 3000, 1);

    Livewire::test(RecentOrdersWidget::class)
        ->assertSee('Gross profit (GBP)')
        ->assertTableColumnStateSet('gross_profit', '£15.00', record: $order)
        ->assertTableColumnStateSet('gross_profit', '£10.00', record: $other);
});

it('shows the gross profit in rand too, at today\'s rate', function (): void {
    zarApiReturns(20.0);
    dashboardStaff();
    $order = dashboardOrder();
    profitLine($order, 4500, 3, ['cost_price' => 1000]);

    Livewire::test(RecentOrdersWidget::class)
        ->assertTableColumnVisible('gross_profit_zar')
        ->assertSee('Gross profit (ZAR)')
        ->assertTableColumnStateSet('gross_profit_zar', 'R 300', record: $order);
});

it('leaves the rand column out while the rate cannot be fetched', function (): void {
    Http::fake(['api.frankfurter.dev/*' => Http::response('', 503)]);
    dashboardStaff();
    profitLine(dashboardOrder(), 4500, 3, ['cost_price' => 1000]);

    Livewire::test(RecentOrdersWidget::class)
        ->assertSee('Gross profit (GBP)')
        ->assertTableColumnHidden('gross_profit_zar')
        ->assertDontSee('Gross profit (ZAR)');
});

it('adds up an order\'s gross profit without the delivery line or another order\'s lines', function (): void {
    $order = dashboardOrder();
    profitLine($order, 4500, 3, ['cost_price' => 1000]);
    profitLine($order, 3000, 1, ['cost_price' => 2500]);
    OrderLine::factory()->create(['order_id' => $order->id, 'purchasable_type' => null, 'purchasable_id' => null, 'type' => 'shipping', 'quantity' => 1, 'sub_total' => 800]);
    profitLine(dashboardOrder(), 9000, 1, ['cost_price' => 1]);

    expect(app(OrderLineProfit::class)->forOrder($order->load('lines')))->toBe(1500 + 500);
});

it('draws the gross profit tile light blue with dark text', function (): void {
    $styles = (string) file_get_contents(resource_path('views/admin/dashboard-tiles.blade.php'));

    expect($styles)->toContain('.sales-tile.sales-tile--sky { background-color: #bfe3f7 !important; color: #0b2540; }')
        ->toContain('.sales-tile.sales-tile--sky .fi-wi-stats-overview-stat-value { color: #0b2540 !important; }');
});
