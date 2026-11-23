<?php

declare(strict_types=1);

use App\Domain\Orders\Invoice;
use App\Models\User;
use Database\Seeders\OldInvoicesCheckSeeder;
use Database\Seeders\OldInvoicesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Lunar\FieldTypes\Text;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Lunar\Models\Product;
use Tests\Support\CatalogueFactory;

// The one-off seeder that brings the old system's invoices onto a customer (database/seeders/OldInvoicesSeeder.php).
// Its data is a JSON file of invoices; these tests give it small files of their own.

beforeEach(function (): void {
    config()->set('store.noindex', true);
    Mail::fake();
    Notification::fake();
});

/** The catalogue's "CADOR" (the old system's "CADOR S1P LOW"), in green, with EU sizes 37 to 39. */
function seedCador(): Product
{
    $sizes = [['4', '37'], ['5', '38'], ['6', '39']];

    $product = CatalogueFactory::product('CADOR', array_map(
        fn (array $size): array => ['colour' => 'Green', 'size' => $size[0], 'stock' => 20],
        $sizes,
    ), 'published', 2680);

    foreach ($product->variants as $variant) {
        $uk = (string) $variant->values->firstWhere('option.handle', 'shoe_size')?->translate('name');
        $variant->update(['attribute_data' => ['eu_size' => new Text(collect($sizes)->firstWhere(0, $uk)[1])]]);
    }

    return $product->fresh();
}

/** @return array{0: Customer, 1: User} */
function seedCustomer(bool $withLogin = true): array
{
    $user = User::factory()->create(['email' => 'buyer@builder.example']);
    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd']);

    if ($withLogin) {
        $user->customers()->attach($customer);
    }

    return [$customer, $user];
}

/** The seeder, reading the given invoices and importing onto the customer. */
function seeder(array $invoices, ?Customer $customer, bool $dryRun = false): OldInvoicesSeeder
{
    $file = tempnam(sys_get_temp_dir(), 'oldinv');
    file_put_contents($file, json_encode($invoices));

    $seeder = new OldInvoicesSeeder;
    $seeder->dataFile = $file;
    $seeder->customerId = $customer?->id ?? 999999;
    $seeder->dryRun = $dryRun;

    return $seeder;
}

/** A numbered, paid invoice of five pairs (net £134.00, VAT £26.80) and an unnumbered, unpaid one of one pair. */
function seedInvoices(): array
{
    return [
        ['id' => 10, 'number' => '15042024/001', 'date' => '2024-04-15', 'time' => '10:59:01', 'po' => 'LMLLP / 29095', 'paid' => true,
            'lines' => [['CADOR S1P LOW', 'green', '38', 3, 2680], ['CADOR S1P LOW', 'green', '39', 2, 2680]]],
        ['id' => 11, 'number' => null, 'date' => '2024-04-15', 'time' => '16:00:00', 'po' => null, 'paid' => false,
            'lines' => [['CADOR S1P LOW', 'Green', '37', 1, 2790]]],
    ];
}

it('imports invoices as placed orders with their old numbers, dates, lines, VAT and totals', function (): void {
    $product = seedCador();
    [$customer, $user] = seedCustomer();

    $seeder = seeder(seedInvoices(), $customer);
    $seeder->run();

    expect($seeder->problems)->toBe([])->and($seeder->imported)->toBe(2);

    $paid = Order::query()->where('meta->imported_invoice->source', 'old system invoice 10')->firstOrFail()->load('lines', 'addresses');

    expect($paid->user_id)->toBe($user->id)
        ->and($paid->customer_id)->toBe($customer->id)
        ->and($paid->status)->toBe('payment-received')
        ->and($paid->placed_at->format('Y-m-d H:i:s'))->toBe('2024-04-15 10:59:01')
        ->and($paid->reference)->not->toBeNull()
        ->and($paid->currency_code)->toBe('GBP')
        ->and($paid->sub_total->value)->toBe(13400)
        ->and($paid->shipping_total->value)->toBe(0)
        ->and($paid->tax_total->value)->toBe(2680)
        ->and($paid->total->value)->toBe(16080)
        ->and($paid->meta['payment_method'])->toBe('account-30-day')
        ->and($paid->meta['imported_invoice']['number'])->toBe('15042024/001')
        ->and($paid->meta['imported_invoice']['po_number'])->toBe('LMLLP / 29095')
        ->and($paid->tax_breakdown->amounts->sum(fn ($amount): int => $amount->price->value))->toBe(2680)
        ->and($paid->tax_breakdown->amounts->first()->percentage)->toBe(20.0)
        ->and(DB::table('invoice_numbers')->where('order_id', $paid->id)->value('number'))->toBe('15042024/001');

    $lines = $paid->lines->where('type', 'physical')->values();

    expect($lines)->toHaveCount(2)
        ->and($lines->sum(fn (OrderLine $line): int => $line->sub_total->value))->toBe(13400)
        ->and($lines->sum(fn (OrderLine $line): int => $line->tax_total->value))->toBe(2680)
        ->and($lines->sum(fn (OrderLine $line): int => $line->total->value))->toBe(16080)
        ->and($lines->pluck('quantity')->all())->toBe([3, 2])
        ->and($lines->first()->unit_price->value)->toBe(2680)
        ->and($lines->first()->description)->toBe('CADOR S1P LOW')
        ->and($lines->first()->option)->toBe('green, EU 38')
        ->and($lines->first()->meta['eu_size'])->toBe('38')
        ->and($lines->first()->purchasable_type)->toBe('product_variant');

    // Each line points at the variant of its own EU size.
    expect($lines->map(fn (OrderLine $line): string => (string) $line->purchasable->attr('eu_size'))->all())->toBe(['38', '39'])
        ->and($lines->every(fn (OrderLine $line): bool => $line->purchasable->product_id === $product->id))->toBeTrue();

    // Bill to and deliver to are the customer's own details.
    expect($paid->addresses)->toHaveCount(2)
        ->and($paid->addresses->pluck('company_name')->unique()->all())->toBe(['Builder Ltd'])
        ->and($paid->addresses->pluck('contact_email')->unique()->all())->toBe(['buyer@builder.example']);

    // The paid invoice shows as paid in the customer's account.
    expect((new Invoice($paid))->isPaid())->toBeTrue();
});

it('numbers an invoice the old system left without a number from its date, after the numbers already used that day', function (): void {
    seedCador();
    [$customer] = seedCustomer();

    // Another order already holds 15042024/002 (and the old invoice 10 keeps /001).
    DB::table('invoice_numbers')->insert(['order_id' => null, 'invoice_date' => '2024-04-15', 'sequence' => 2, 'number' => '15042024/002', 'created_at' => now()]);

    seeder(seedInvoices(), $customer)->run();

    $unnumbered = Order::query()->where('meta->imported_invoice->source', 'old system invoice 11')->firstOrFail();

    expect($unnumbered->meta['imported_invoice']['number'])->toBe('15042024/003')
        ->and(DB::table('invoice_numbers')->where('order_id', $unnumbered->id)->value('number'))->toBe('15042024/003')
        ->and(DB::table('invoice_numbers')->where('order_id', $unnumbered->id)->value('sequence'))->toBe(3);
});

it('imports an invoice the old system marked unpaid as an open invoice on account terms', function (): void {
    seedCador();
    [$customer] = seedCustomer();

    seeder(seedInvoices(), $customer)->run();

    $unpaid = Order::query()->where('meta->imported_invoice->source', 'old system invoice 11')->firstOrFail();

    expect($unpaid->status)->toBe('awaiting-payment')
        ->and($unpaid->meta['payment_method'])->toBe('account-30-day')
        ->and($unpaid->total->value)->toBe(3348)
        ->and((new Invoice($unpaid))->isPaid())->toBeFalse();
});

it('does nothing the second time', function (): void {
    seedCador();
    [$customer] = seedCustomer();

    seeder(seedInvoices(), $customer)->run();
    $again = seeder(seedInvoices(), $customer);
    $again->run();

    expect($again->imported)->toBe(0)
        ->and($again->problems)->toBe([])
        ->and(Order::query()->count())->toBe(2)
        ->and(DB::table('invoice_numbers')->count())->toBe(2);
});

it('writes a line whose product or variant is not in the catalogue as the old invoice had it, linked to nothing', function (): void {
    seedCador();
    [$customer] = seedCustomer();

    $seeder = seeder([['id' => 12, 'number' => '16042024/001', 'date' => '2024-04-16', 'time' => '09:00:00', 'po' => null, 'paid' => true,
        'lines' => [['PATRICIA', 'black', '39', 2, 2680], ['PATRICIA', 'black', '40', 1, 2680], ['CADOR S1P LOW', 'pink', '38', 1, 2680], ['CADOR S1P LOW', 'green', '38', 1, 2680]]]], $customer);
    $seeder->run();

    expect($seeder->problems)->toBe([])
        ->and($seeder->imported)->toBe(1)
        ->and($seeder->notes)->toBe([
            'Product "PATRICIA" is not in the catalogue: written as on the old invoice, not linked to a product (EU 39, 40; old invoices 12).',
            'No variant of "CADOR" in pink: written as on the old invoice, not linked to a product (EU 38; old invoices 12).',
        ]);

    $order = Order::query()->firstOrFail()->load('lines');
    $lines = $order->lines->where('type', 'physical')->values();

    // The whole invoice is there with its money, the three lines with no catalogue match as the old invoice had them.
    expect($lines)->toHaveCount(4)
        ->and($order->sub_total->value)->toBe(5 * 2680)
        ->and($order->total->value)->toBe(5 * 2680 + 2680)
        ->and($lines->pluck('description')->all())->toBe(['PATRICIA', 'PATRICIA', 'CADOR S1P LOW', 'CADOR S1P LOW'])
        ->and($lines->pluck('option')->all())->toBe(['black, EU 39', 'black, EU 40', 'pink, EU 38', 'green, EU 38'])
        ->and($lines->pluck('purchasable_id')->map(fn (mixed $id): bool => $id === null)->all())->toBe([true, true, true, false])
        ->and($lines->first()->identifier)->toBe('old-system')
        ->and($lines->first()->quantity)->toBe(2)
        ->and($lines->first()->sub_total->value)->toBe(5360)
        ->and($lines->first()->purchasable)->toBeNull();
});

it('uses the first variant when the catalogue has two for the same colour and size, and says so', function (): void {
    $product = seedCador();
    [$customer] = seedCustomer();

    $first = $product->variants->first(fn ($variant): bool => (string) $variant->attr('eu_size') === '38');
    $twin = $first->replicate();
    $twin->sku = $first->sku.'-TWIN';
    $twin->save();
    $twin->values()->sync($first->values->pluck('id'));

    $seeder = seeder([seedInvoices()[0]], $customer);
    $seeder->run();

    expect($seeder->problems)->toBe([])
        ->and(collect($seeder->notes)->contains(fn (string $note): bool => str_contains($note, 'lowest variant id was used')))->toBeTrue()
        ->and(OrderLine::query()->where('purchasable_id', $first->id)->count())->toBe(1)
        ->and(OrderLine::query()->where('purchasable_id', $twin->id)->count())->toBe(0);
});

it('reads and checks everything but writes nothing as a dry run', function (): void {
    seedCador();
    [$customer] = seedCustomer();

    $seeder = seeder(seedInvoices(), $customer, dryRun: true);
    $seeder->run();

    expect($seeder->problems)->toBe([])
        ->and($seeder->imported)->toBe(2)
        ->and(Order::query()->count())->toBe(0)
        ->and(DB::table('invoice_numbers')->count())->toBe(0);
});

it('has a check seeder that runs the same checks without writing', function (): void {
    seedCador();
    seedCustomer();

    $this->seed(OldInvoicesCheckSeeder::class);

    expect(Order::query()->count())->toBe(0);
});

it('stops when the customer does not exist or has no login', function (): void {
    seedCador();
    [$noLogin] = seedCustomer(withLogin: false);

    $missing = seeder(seedInvoices(), null);
    $missing->run();

    $unreachable = seeder(seedInvoices(), $noLogin);
    $unreachable->run();

    expect($missing->problems[0])->toContain('does not exist')
        ->and($unreachable->problems[0])->toContain('has no login')
        ->and(Order::query()->count())->toBe(0);
});

it('refuses an old number another order already holds', function (): void {
    seedCador();
    [$customer] = seedCustomer();

    DB::table('invoice_numbers')->insert(['order_id' => null, 'invoice_date' => '2024-04-15', 'sequence' => 1, 'number' => '15042024/001', 'created_at' => now()]);

    $seeder = seeder([seedInvoices()[0]], $customer);
    $seeder->run();

    expect($seeder->problems)->toHaveCount(1)
        ->and($seeder->problems[0])->toContain('15042024/001')
        ->and(Order::query()->count())->toBe(0);
});

it('sends no email, moves no stock and makes no cart', function (): void {
    $product = seedCador();
    [$customer] = seedCustomer();

    seeder(seedInvoices(), $customer)->run();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();

    expect($product->variants()->pluck('stock')->unique()->all())->toBe([20])
        ->and(DB::table('lunar_carts')->count())->toBe(0);
});

it('shares the penny when the lines round differently from the invoice', function (): void {
    seedCador();
    [$customer] = seedCustomer();

    // Three pairs at £0.03: each line's VAT rounds to 1p (0.6p), the invoice's VAT of 9p x 20% rounds to 2p.
    seeder([['id' => 20, 'number' => '17042024/001', 'date' => '2024-04-17', 'time' => '09:00:00', 'po' => null, 'paid' => true,
        'lines' => [['CADOR S1P LOW', 'green', '37', 1, 3], ['CADOR S1P LOW', 'green', '38', 1, 3], ['CADOR S1P LOW', 'green', '39', 1, 3]]]], $customer)->run();

    $order = Order::query()->firstOrFail()->load('lines');

    expect($order->tax_total->value)->toBe(2)
        ->and($order->total->value)->toBe(11)
        ->and($order->lines->sum(fn (OrderLine $line): int => $line->tax_total->value))->toBe(2);
});
