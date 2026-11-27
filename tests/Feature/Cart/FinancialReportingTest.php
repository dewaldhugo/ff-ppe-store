<?php

declare(strict_types=1);

use App\Admin\Pages\FinancialReporting;
use App\Domain\Orders\FinancialReport;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Channel;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

// The yearly Financial reporting pages in the admin (App\Admin\Pages\FinancialReporting, App\Domain\Orders\FinancialReport).

beforeEach(function (): void {
    config()->set('store.noindex', true);
    Carbon::setTestNow('2026-03-15 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function reportOrder(array $attributes = [], array $lines = []): Order
{
    // Without events: the store's own hooks would number the order and email staff.
    $order = Order::withoutEvents(fn (): Order => Order::factory()->create([
        'channel_id' => Channel::getDefault()->id,
        'status' => 'payment-received',
        'sub_total' => 10000,
        'tax_total' => 2000,
        'total' => 12000,
        'placed_at' => '2026-03-10 09:00:00',
        ...$attributes,
    ]));

    // Each line: [description, quantity, sub_total (pence), cost per unit (pence), type].
    foreach ($lines as [$description, $quantity, $subTotal, $cost, $type]) {
        OrderLine::factory()->create([
            'order_id' => $order->id,
            'purchasable_type' => null,
            'purchasable_id' => null,
            'type' => $type ?? 'physical',
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => intdiv($subTotal, max(1, $quantity)),
            'sub_total' => $subTotal,
            'total' => (int) round($subTotal * 1.2),
            'meta' => $cost === null ? [] : ['cost_price' => $cost],
        ]);
    }

    return $order;
}

function reportStaff(bool $admin = true): Staff
{
    $staff = Staff::factory()->create(['admin' => $admin]);
    test()->actingAs($staff, 'staff');
    Filament::setCurrentPanel(Filament::getPanel('lunar'));

    return $staff;
}

function reportCustomer(string $company): Customer
{
    return Customer::query()->create(['first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => $company]);
}

// --- The figures ------------------------------------------------------------------------------

it('adds up the year\'s revenue, gross profit, units and margin', function (): void {
    // March: £100.00 ex VAT order, goods £90.00 (3 pairs costing £20.00 each = £60.00 cost, £30.00 profit) plus £10.00 delivery.
    reportOrder(['sub_total' => 10000], [['CADOR S1P LOW', 3, 9000, 2000, 'physical'], ['Delivery', 1, 1000, null, 'shipping']]);
    // January: £50.00 ex VAT, goods £50.00 (1 pair costing £30.00 = £20.00 profit).
    reportOrder(['sub_total' => 5000, 'placed_at' => '2026-01-20 10:00:00'], [['MODULO S3S LOW', 1, 5000, 3000, 'physical']]);

    $report = app(FinancialReport::class)->forYear(2026);

    expect($report['revenue'])->toBe(15000)
        ->and($report['grossProfit'])->toBe(5000)
        ->and($report['unitsSold'])->toBe(4)
        // £50.00 profit on £140.00 of goods sold: 35.7%.
        ->and($report['margin'])->toBe('35.7%');
});

it('has no margin before the first sale', function (): void {
    $report = app(FinancialReport::class)->forYear(2026);

    expect($report['revenue'])->toBe(0)->and($report['grossProfit'])->toBe(0)->and($report['unitsSold'])->toBe(0)->and($report['margin'])->toBeNull();
});

it('splits the year into months, only the months so far in this year and all twelve in an earlier one', function (): void {
    reportOrder(['sub_total' => 10000, 'placed_at' => '2026-01-05 10:00:00'], [['A', 2, 10000, 4000, 'physical']]);
    reportOrder(['sub_total' => 20000, 'placed_at' => '2026-03-05 10:00:00'], [['A', 5, 20000, 6000, 'physical']]);
    reportOrder(['sub_total' => 7000, 'placed_at' => '2025-11-05 10:00:00'], [['A', 1, 7000, 2000, 'physical']]);

    $this_year = app(FinancialReport::class)->forYear(2026);
    $last_year = app(FinancialReport::class)->forYear(2025);

    expect($this_year['months'])->toBe(['Jan', 'Feb', 'Mar'])
        ->and($this_year['turnover'])->toBe([10000, 0, 20000])
        ->and($this_year['units'])->toBe([2, 0, 5])
        ->and($this_year['profit'])->toBe([2000, 0, 5000])
        ->and($last_year['months'])->toHaveCount(12)
        ->and($last_year['turnover'][10])->toBe(7000)
        ->and($last_year['revenue'])->toBe(7000);
});

it('counts the same orders as the dashboard: placed, and not failed or cancelled', function (): void {
    reportOrder(['sub_total' => 10000], [['A', 1, 10000, 5000, 'physical']]);
    reportOrder(['sub_total' => 6000, 'status' => 'awaiting-payment'], [['A', 1, 6000, 3000, 'physical']]);   // on account: counts
    reportOrder(['sub_total' => 99900, 'status' => 'cancelled'], [['A', 9, 99900, 1, 'physical']]);
    reportOrder(['sub_total' => 88800, 'status' => 'failed'], [['A', 9, 88800, 1, 'physical']]);
    reportOrder(['sub_total' => 77700, 'placed_at' => null], [['A', 9, 77700, 1, 'physical']]);

    $report = app(FinancialReport::class)->forYear(2026);

    expect($report['revenue'])->toBe(16000)->and($report['unitsSold'])->toBe(2)->and($report['grossProfit'])->toBe(8000);
});

it('names the top customers and styles by units, most first', function (): void {
    $builder = reportCustomer('Builder Ltd');
    $roofer = reportCustomer('Roofer Ltd');

    reportOrder(['customer_id' => $builder->id], [['CADOR S1P LOW', 10, 10000, 100, 'physical'], ['MODULO S3S LOW', 2, 2000, 100, 'physical']]);
    reportOrder(['customer_id' => $roofer->id], [['MODULO S3S LOW', 7, 7000, 100, 'physical']]);
    reportOrder(['customer_id' => $builder->id], [['CADOR S1P LOW', 1, 1000, 100, 'physical']]);

    $report = app(FinancialReport::class)->forYear(2026);

    expect($report['customers'])->toBe([['label' => 'Builder Ltd', 'units' => 13], ['label' => 'Roofer Ltd', 'units' => 7]])
        ->and($report['styles'])->toBe([['label' => 'CADOR S1P LOW', 'units' => 11], ['label' => 'MODULO S3S LOW', 'units' => 9]]);
});

it('shows only the ten best customers and styles', function (): void {
    foreach (range(1, 12) as $number) {
        reportOrder(['customer_id' => reportCustomer('Customer '.str_pad((string) $number, 2, '0', STR_PAD_LEFT))->id], [['Style '.str_pad((string) $number, 2, '0', STR_PAD_LEFT), $number, $number * 100, 1, 'physical']]);
    }

    $report = app(FinancialReport::class)->forYear(2026);

    expect($report['customers'])->toHaveCount(10)
        ->and($report['customers'][0])->toBe(['label' => 'Customer 12', 'units' => 12])
        ->and($report['styles'])->toHaveCount(10);
});

it('lists the years from the first order to this year, and this year when there are no orders', function (): void {
    expect(app(FinancialReport::class)->years())->toBe([2026]);

    reportOrder(['placed_at' => '2023-06-01 10:00:00']);
    reportOrder(['placed_at' => '2025-02-01 10:00:00']);

    expect(app(FinancialReport::class)->years())->toBe([2023, 2024, 2025, 2026]);
});

// --- The page ---------------------------------------------------------------------------------

it('shows the tiles and the five charts for the year', function (): void {
    reportStaff();
    $builder = reportCustomer('Builder Ltd');
    reportOrder(['customer_id' => $builder->id, 'sub_total' => 10000], [['CADOR S1P LOW', 3, 9000, 2000, 'physical']]);

    $html = Livewire::test(FinancialReporting::class, ['year' => 2026])
        ->assertSee('Financial reporting | 2026')
        ->assertSee('Revenue (ex VAT)')->assertSee('£100.00')
        ->assertSee('Gross profit')->assertSee('£30.00')
        ->assertSee('Units sold')
        ->assertSee('Gross profit margin')->assertSee('33.3%')
        ->assertSee('Builder Ltd')
        ->assertSee('CADOR S1P LOW')
        ->html();

    preg_match_all('/data-chart="([a-z-]+)"/', $html, $charts);

    expect($charts[1])->toBe(['monthly-unit-sales', 'monthly-turnover', 'units-per-customer', 'monthly-gross-profit', 'top-styles-sold']);
});

it('draws a bar for every month and a point for every month', function (): void {
    reportStaff();
    reportOrder(['sub_total' => 10000], [['A', 3, 9000, 2000, 'physical']]);

    $html = Livewire::test(FinancialReporting::class, ['year' => 2026])->html();

    // Jan to Mar: three bars on the turnover chart, three points on each line chart.
    preg_match('#data-chart="monthly-turnover".*?</svg>#s', $html, $turnover);
    preg_match('#data-chart="monthly-gross-profit".*?</svg>#s', $html, $profit);

    expect(substr_count($turnover[0], 'data-bar'))->toBe(3)
        ->and(substr_count($profit[0], 'data-point'))->toBe(3)
        ->and($turnover[0])->toContain('Mar: £100');
});

it('shows the current year when no year is given', function (): void {
    reportStaff();

    Livewire::test(FinancialReporting::class)->assertSet('year', 2026)->assertSee('Financial reporting | 2026');
});

it('draws a year with no orders as empty charts, not an error', function (): void {
    reportStaff();

    Livewire::test(FinancialReporting::class, ['year' => 2024])->assertOk()->assertSee('£0.00')->assertSee('n/a');
});

it('is a 404 for a year before 2000 or after this year', function (): void {
    reportStaff();

    $this->get(FinancialReporting::getUrl(['year' => 1999]))->assertNotFound();
    $this->get(FinancialReporting::getUrl(['year' => 2027]))->assertNotFound();
});

it('puts Financial reporting under Sales with a year under it for each year with orders', function (): void {
    reportStaff();
    reportOrder(['placed_at' => '2025-02-01 10:00:00']);

    $items = FinancialReporting::getNavigationItems();
    $labels = array_map(fn ($item): string => (string) $item->getLabel(), $items);

    expect($labels)->toBe(['Financial reporting', '2025', '2026'])
        ->and($items[0]->getGroup())->toBe(__('lunarpanel::global.sections.sales'))
        ->and($items[1]->getParentItem())->toBe('Financial reporting')
        ->and($items[1]->getUrl())->toBe(FinancialReporting::getUrl(['year' => 2025]));
});

it('is for staff who see orders only', function (): void {
    reportStaff(admin: false);

    expect(FinancialReporting::canAccess())->toBeFalse();

    reportStaff(admin: true);

    expect(FinancialReporting::canAccess())->toBeTrue();
});
