<?php

declare(strict_types=1);

use App\Domain\Catalogue\Options\ShoeSizes;
use App\Domain\Orders\Invoice;
use App\Domain\Orders\InvoicePdf;
use App\Domain\Orders\LineDetails;
use Illuminate\Support\Facades\Blade;
use Lunar\Base\ValueObjects\Cart\TaxBreakdown;
use Lunar\Models\Channel;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;

// An order line's sizes are worded the same everywhere: colour, then UK size, then EU size. The invoice and its PDF show them as
// columns (UK Size before EU Size, VAT and Amount headings right-aligned, Quantity and Unit Price left-aligned with their figures);
// the order page, the confirmation page and the email show them in a sentence (App\Domain\Orders\LineDetails).

beforeEach(fn () => config()->set('store.noindex', true));

/** A placed order with one goods line carrying the given meta. */
function sizedOrder(array $meta, string $option = 'Plain option'): Order
{
    $order = Order::withoutEvents(fn (): Order => Order::factory()->create([
        'channel_id' => Channel::getDefault()->id, 'status' => 'payment-received', 'placed_at' => '2026-03-10 09:00:00',
        'meta' => ['payment_method' => 'account-30-day'],
    ]));

    OrderLine::factory()->create([
        'order_id' => $order->id, 'purchasable_type' => null, 'purchasable_id' => null, 'type' => 'physical',
        'description' => 'CADOR', 'option' => $option, 'identifier' => 'SJ-1', 'quantity' => 2, 'unit_price' => 2790,
        'sub_total' => 5580, 'tax_total' => 1116, 'total' => 6696, 'discount_total' => 0, 'tax_breakdown' => new TaxBreakdown, 'meta' => $meta,
    ]);

    return $order->fresh(['lines']);
}

// --- The wording ------------------------------------------------------------------------------

it('words a line as colour, UK size, EU size, and falls back to the option text', function (): void {
    $line = fn (array $meta, string $option = 'Plain option'): OrderLine => sizedOrder($meta, $option)->lines->first();

    expect(LineDetails::text($line(['colour' => 'Light grey', 'size' => '9', 'size_is_uk' => true, 'eu_size' => '43'])))->toBe('Light grey, UK 9, EU 43')
        // A size that is not a UK footwear size is worded as it is.
        ->and(LineDetails::text($line(['colour' => 'Navy', 'size' => 'XL', 'size_is_uk' => false])))->toBe('Navy, XL')
        // Only an EU size, only a colour.
        ->and(LineDetails::text($line(['colour' => 'Black', 'eu_size' => '41'])))->toBe('Black, EU 41')
        ->and(LineDetails::text($line(['colour' => 'Black'])))->toBe('Black')
        // Placed before the meta was kept: Lunar's own option text.
        ->and(LineDetails::text($line([], ' Black, 9 ')))->toBe('Black, 9');
});

it('looks up the UK size for an EU size on the size chart', function (): void {
    expect(ShoeSizes::ukFor('36'))->toBe('3.5')
        ->and(ShoeSizes::ukFor('43'))->toBe('9')
        ->and(ShoeSizes::ukFor(40.0))->toBe('6.5')
        ->and(ShoeSizes::ukFor('33'))->toBe('1')
        // No entry, an infant size, and something that is not a number.
        ->and(ShoeSizes::ukFor('60'))->toBeNull()
        ->and(ShoeSizes::ukFor('26'))->toBeNull()
        ->and(ShoeSizes::ukFor('XL'))->toBeNull();
});

it('shows the sizes in an order list as colour, UK size, EU size', function (): void {
    $order = sizedOrder(['colour' => 'Light grey', 'size' => '9', 'size_is_uk' => true, 'eu_size' => '43']);

    $html = Blade::render('<x-orders.detail :order="$order" />', ['order' => $order]);

    expect($html)->toContain('Code SJ-1, Light grey, UK 9, EU 43');
});

// --- The PDF ----------------------------------------------------------------------------------

it('lays the PDF\'s lines table out with UK Size before EU Size, VAT and Amount right-aligned and Quantity and Unit Price left', function (): void {
    // An imported line has only an EU size: the UK Size column is still there, empty.
    $order = sizedOrder(['colour' => 'Black', 'eu_size' => '41']);

    $html = view('admin.pdf.invoice', [
        'invoice' => new Invoice($order), 'order' => $order, 'colours' => InvoicePdf::COLOURS, 'logo' => public_path('images/frontline-logo.png'),
    ])->render();

    preg_match('#<thead>.*?</thead>#s', $html, $head);
    preg_match_all('#<th( class="right")?>(.*?)</th>#s', $head[0], $headings, PREG_SET_ORDER);

    $names = array_map(fn (array $heading): string => $heading[2], $headings);
    $right = array_map(fn (array $heading): string => $heading[2], array_filter($headings, fn (array $heading): bool => $heading[1] !== ''));

    expect($names)->toBe(['Description', 'Colour', 'UK Size', 'EU Size', 'Quantity', 'Unit Price', 'VAT', 'Amount'])
        ->and(array_values($right))->toBe(['VAT', 'Amount']);

    // The figures follow their headings: quantity and unit price on the left, VAT and amount on the right.
    preg_match('#<tbody>\s*<tr>(.*?)</tr>#s', $html, $row);
    preg_match_all('#<td( class="[^"]*")?>#', $row[1], $cells, PREG_SET_ORDER);

    expect(array_map(fn (array $cell): string => $cell[1] ?? '', $cells))->toBe(['', '', '', '', '', '', ' class="right"', ' class="right bold"']);
});
