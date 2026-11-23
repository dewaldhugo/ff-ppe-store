<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Checkout\PaymentMethods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Base\OrderReferenceGeneratorInterface;
use Lunar\Base\ValueObjects\Cart\TaxBreakdown;
use Lunar\Base\ValueObjects\Cart\TaxBreakdownAmount;
use Lunar\DataTypes\Price;
use Lunar\Models\Address;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxRateAmount;
use Throwable;

/**
 * One-off: brings the invoices of the old system onto customer 7 as placed orders, with the old invoice numbers,
 * dates, lines, VAT and totals. Nothing about the old customer is used; the order carries the name and default
 * address the store holds for customer 7.
 *
 *   php artisan db:seed --class=OldInvoicesCheckSeeder --force    (reads everything, writes nothing)
 *   php artisan db:seed --class=OldInvoicesSeeder --force         (the import)
 *
 * The data is `database/seeders/data/old-invoices.json`, made from the old system's two exports (invoices and their
 * lines). Prices are pence ex VAT, sizes are EU sizes.
 *
 * - Every line is matched to a catalogue variant (product name, colour, EU size). A line that cannot be matched (the
 *   product or the variant is not in the catalogue) is written exactly as the old invoice had it, linked to no
 *   product, and listed in the run's notes. It is for reference only. No product is made up.
 * - All or nothing: if anything else is wrong (no customer, an invoice number already taken) nothing is written.
 * - VAT is 20% of the invoice's net, rounded half up once, as the old invoices were. The export had no total column,
 *   so the totals are worked out from the lines and nothing checks them.
 * - An invoice the old system gave no number is numbered from its date, after the numbers already used that day.
 * - Written with model events off: no email, no stock movement, no new invoice number from the store's own hooks.
 * - Running it again does nothing for invoices already imported (their old system id is kept on the order).
 * - An invoice the old system marked unpaid is imported as Awaiting Payment on 30 day account terms, so it shows as
 *   an open invoice in the customer's account.
 */
final class OldInvoicesSeeder extends Seeder
{
    public int $customerId = 7;

    public string $dataFile = '';

    /** Read and match everything, write nothing. */
    public bool $dryRun = false;

    private const int VAT_PERCENT = 20;

    /**
     * Old product name => the name of the catalogue product. An old name not listed is looked up as it is.
     * "CADOR S1P LOW" is the catalogue's "CADOR". PATRICIA is not in the catalogue (only a draft "Patricia" in beige).
     */
    private const array PRODUCTS = [
        'CADOR S1P LOW' => 'CADOR',
        'DAKARS3LEA' => 'DAKAR S3 LEATHER',
        'FLOWS3MID' => 'FLOW S3 MID',
    ];

    /** @var list<string> What could not be matched or written; empty when the data is clean. */
    public array $problems = [];

    /** @var array<int|string, string> Notes on what was done. */
    public array $notes = [];

    /** @var array<string, array{text: string, sizes: array<string, true>, invoices: array<int, true>}> */
    private array $unmatched = [];

    public int $imported = 0;

    /** @var array<string, list<int>>|null Normalised product name => product ids. */
    private ?array $productIds = null;

    /** @var array<int, \Illuminate\Support\Collection<int, ProductVariant>> */
    private array $variants = [];

    public function run(): void
    {
        $file = $this->dataFile !== '' ? $this->dataFile : database_path('seeders/data/old-invoices.json');
        $invoices = json_decode((string) @file_get_contents($file), true);

        if (! is_array($invoices) || $invoices === []) {
            $this->problems[] = 'Could not read the invoices from '.$file.'.';
            $this->report();

            return;
        }

        $customer = Customer::query()->find($this->customerId);
        $user = $customer?->users()->orderBy('id')->first();

        if ($customer === null || $user === null) {
            $this->problems[] = 'Customer '.$this->customerId.' '.($customer === null ? 'does not exist.' : 'has no login, so the invoices would not appear in their account.');
            $this->report();

            return;
        }

        $rate = $this->taxRate();

        if ($rate === null) {
            $this->problems[] = 'The store has no VAT rate of '.self::VAT_PERCENT.'%.';
            $this->report();

            return;
        }

        // Which invoices are already there, and which numbers and day sequences are taken.
        $done = [];
        $taken = [];

        foreach (DB::table('invoice_numbers')->get(['number', 'invoice_date', 'sequence', 'order_id']) as $row) {
            $taken[substr((string) $row->invoice_date, 0, 10).'#'.$row->sequence] = $row->number;
        }

        foreach ($invoices as $invoice) {
            $done[$invoice['id']] = Order::query()->where('meta->imported_invoice->source', $this->source($invoice))->value('id');
        }

        // Every explicit old number keeps its place; numberless invoices are fitted around them.
        foreach ($invoices as $invoice) {
            if ($invoice['number'] !== null && $done[$invoice['id']] === null) {
                $this->claim($invoice, $taken);
            }
        }

        $plan = [];

        foreach ($invoices as $invoice) {
            if ($done[$invoice['id']] !== null) {
                $this->notes[] = 'Invoice '.$invoice['id'].' is already imported (order '.$done[$invoice['id']].').';

                continue;
            }

            $matched = [];

            foreach ($invoice['lines'] as $index => [$product, $colour, $eu]) {
                $variant = $this->variant($invoice, $index, $product, $colour, $eu);

                if ($variant !== null) {
                    $matched[$index] = $variant;
                }
            }

            if ($invoice['number'] === null) {
                $invoice['number'] = $this->allocate($invoice['date'], $taken);
                $this->notes[] = 'Invoice '.$invoice['id'].' (old system, '.$invoice['date'].') had no number: it becomes '.$invoice['number'].'.';
            }

            $plan[] = [$invoice, $matched];
        }

        // A line that cannot be matched is still imported, as the old invoice had it, with no link to a product.
        foreach ($this->unmatched as $item) {
            $sizes = array_keys($item['sizes']);
            sort($sizes, SORT_NUMERIC);
            $invoiceIds = array_keys($item['invoices']);
            sort($invoiceIds);
            $this->notes[] = $item['text'].': written as on the old invoice, not linked to a product (EU '.implode(', ', $sizes).'; old invoices '.implode(', ', $invoiceIds).').';
        }

        if ($this->problems !== []) {
            $this->problems = array_values(array_unique($this->problems));
            $this->report();

            return;
        }

        if ($this->dryRun) {
            $this->imported = count($plan);
            $this->report();

            return;
        }

        $orders = Order::withoutEvents(fn (): array => DB::transaction(function () use ($plan, $customer, $user, $rate): array {
            $orders = [];

            foreach ($plan as [$invoice, $matched]) {
                $orders[] = $this->write($invoice, $matched, $customer, $user, $rate);
            }

            return $orders;
        }));

        $this->imported = count($orders);

        // The admin's order search. A search outage must not undo orders that are already saved.
        foreach ($orders as $order) {
            try {
                $order->searchable();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->report();
    }

    /** @param array<string, mixed> $invoice */
    private function source(array $invoice): string
    {
        return 'old system invoice '.$invoice['id'];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<string, string>  $taken
     */
    private function claim(array $invoice, array &$taken): void
    {
        $number = (string) $invoice['number'];
        $sequence = (int) substr($number, (int) strrpos($number, '/') + 1);
        $key = $invoice['date'].'#'.$sequence;

        if (! str_contains($number, '/') || strlen($number) > 20) {
            $this->problems[] = 'Invoice '.$invoice['id'].': the number "'.$number.'" cannot be stored.';
        } elseif (in_array($number, $taken, true)) {
            $this->problems[] = 'Invoice '.$invoice['id'].': the number '.$number.' is already used by another invoice or order.';
        } elseif (isset($taken[$key])) {
            $this->problems[] = 'Invoice '.$invoice['id'].': '.$taken[$key].' already has sequence '.$sequence.' on '.$invoice['date'].'.';
        } else {
            $taken[$key] = $number;
        }
    }

    /**
     * The next free number for a day, DDMMYYYY/NNN.
     *
     * @param  array<string, string>  $taken
     */
    private function allocate(string $date, array &$taken): string
    {
        $sequence = 1;

        while (isset($taken[$date.'#'.$sequence])) {
            $sequence++;
        }

        $number = date('dmY', (int) strtotime($date)).'/'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
        $taken[$date.'#'.$sequence] = $number;

        return $number;
    }

    /** The variant for a line, or null with the reason added to the problems. */
    private function variant(array $invoice, int $index, string $product, string $colour, string $eu): ?ProductVariant
    {
        $name = self::PRODUCTS[$product] ?? $product;
        $ids = $this->products()[$this->normal($name)] ?? [];

        if ($ids === []) {
            $this->unmatched($product.'|', 'Product "'.$name.'" is not in the catalogue', $eu, $invoice);

            return null;
        }

        if (count($ids) > 1) {
            $this->unmatched($product.'|', 'More than one product is called "'.$name.'" (ids '.implode(', ', $ids).')', $eu, $invoice);

            return null;
        }

        $matches = $this->variantsOf($ids[0])->filter(
            fn (ProductVariant $variant): bool => $this->normal((string) $variant->attr('eu_size')) === $this->normal($eu)
                && $variant->values->contains(fn ($value): bool => $value->option->handle === 'colour' && $this->normal((string) $value->translate('name')) === $this->normal($colour))
        )->sortBy('id')->values();

        if ($matches->isEmpty()) {
            $this->unmatched($product.'|'.$colour, 'No variant of "'.$name.'" in '.$colour, $eu, $invoice);

            return null;
        }

        // The vendor lists some shoes twice, with the same colour and size and different SKUs. The invoice cannot say
        // which one was bought, and nothing on the invoice depends on it, so the first is used and the run says so.
        if ($matches->count() > 1) {
            $this->notes[$product.'|'.$colour.'|several'] = '"'.$name.'" in '.$colour.': the catalogue has more than one variant for the same size (different SKUs). The lowest variant id was used.';
        }

        return $matches->first();
    }

    /** Collects what cannot be matched, one line per product and colour, with the sizes and invoices affected. */
    private function unmatched(string $key, string $text, string $eu, array $invoice): void
    {
        $this->unmatched[$key]['text'] = $text;
        $this->unmatched[$key]['sizes'][$eu] = true;
        $this->unmatched[$key]['invoices'][$invoice['id']] = true;
    }

    /** @return array<string, list<int>> */
    private function products(): array
    {
        if ($this->productIds === null) {
            $this->productIds = [];

            foreach (Product::query()->get(['id', 'attribute_data']) as $product) {
                $this->productIds[$this->normal((string) $product->translateAttribute('name'))][] = (int) $product->getKey();
            }
        }

        return $this->productIds;
    }

    /** @return \Illuminate\Support\Collection<int, ProductVariant> */
    private function variantsOf(int $productId): \Illuminate\Support\Collection
    {
        return $this->variants[$productId] ??= ProductVariant::query()->where('product_id', $productId)->with('values.option')->get();
    }

    private function normal(string $text): string
    {
        return Str::lower((string) preg_replace('/\s+/', ' ', trim($text)));
    }

    private function taxRate(): ?TaxRateAmount
    {
        $query = fn () => TaxRateAmount::query()->with('taxRate')->whereBetween('percentage', [self::VAT_PERCENT - 0.001, self::VAT_PERCENT + 0.001]);

        return $query()->where('tax_class_id', CatalogueKeys::taxClass('standard')->id)->first() ?? $query()->first();
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<int, ProductVariant>  $variants  By line index.
     */
    private function write(array $invoice, array $variants, Customer $customer, Model $user, TaxRateAmount $rate): Order
    {
        $currency = Currency::getDefault();
        $placedAt = $invoice['date'].' '.$invoice['time'];
        $number = (string) $invoice['number'];

        $subs = [];
        $net = 0;

        foreach ($invoice['lines'] as $index => [, , , $quantity, $unit]) {
            $subs[$index] = $quantity * $unit;
            $net += $subs[$index];
        }

        // VAT once on the invoice, rounded half up; each line's share is its own rounding, nudged a penny at a time
        // (largest first) until the lines add up to the invoice's VAT.
        $vat = intdiv($net * self::VAT_PERCENT + 50, 100);
        $tax = array_map(fn (int $sub): int => intdiv($sub * self::VAT_PERCENT + 50, 100), $subs);
        $diff = $vat - array_sum($tax);
        $order = array_keys($subs);
        usort($order, fn (int $a, int $b): int => $subs[$b] <=> $subs[$a]);

        for ($i = 0; $diff !== 0 && $order !== []; $i++) {
            $step = $diff > 0 ? 1 : -1;
            $tax[$order[$i % count($order)]] += $step;
            $diff -= $step;
        }

        $record = Order::query()->create([
            'user_id' => $user->getKey(),
            'customer_id' => $customer->getKey(),
            'channel_id' => Channel::getDefault()->id,
            'status' => $invoice['paid'] ? 'payment-received' : 'awaiting-payment',
            'sub_total' => $net,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => $vat,
            'total' => $net + $vat,
            'tax_breakdown' => $this->breakdown($vat, $rate, $currency, keyed: true),
            'currency_code' => $currency->code,
            'compare_currency_code' => $currency->code,
            'exchange_rate' => 1,
            'placed_at' => $placedAt,
            'meta' => [
                'payment_method' => PaymentMethods::ACCOUNT,
                'imported_invoice' => array_filter([
                    'number' => $number,
                    'source' => $this->source($invoice),
                    'po_number' => $invoice['po'],
                    'imported_at' => now()->toDateTimeString(),
                ], fn (mixed $value): bool => $value !== null),
            ],
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ]);

        $record->update(['reference' => app(OrderReferenceGeneratorInterface::class)->generate($record)]);

        foreach ($invoice['lines'] as $index => [$product, $colour, $eu, $quantity, $unit]) {
            // No variant: the line stays as the old invoice had it, linked to nothing (reorder skips it as no longer sold).
            $variant = $variants[$index] ?? null;

            $record->lines()->create([
                'purchasable_type' => $variant?->getMorphClass(),
                'purchasable_id' => $variant?->getKey(),
                'type' => 'physical',
                'description' => $product,
                'option' => $colour.', EU '.$eu,
                'identifier' => $variant === null ? 'old-system' : (string) $variant->sku,
                'unit_price' => $unit,
                'unit_quantity' => 1,
                'quantity' => $quantity,
                'sub_total' => $subs[$index],
                'discount_total' => 0,
                'tax_breakdown' => $this->breakdown($tax[$index], $rate, $currency, keyed: false),
                'tax_total' => $tax[$index],
                'total' => $subs[$index] + $tax[$index],
                'meta' => ['colour' => $colour, 'eu_size' => $eu],
                'created_at' => $placedAt,
                'updated_at' => $placedAt,
            ]);
        }

        $this->addresses($record, $customer, (string) $user->getAttribute('email'));

        // The number as it was, so the store's own numbers carry on after it.
        DB::table('invoice_numbers')->insert([
            'order_id' => $record->getKey(),
            'invoice_date' => $invoice['date'],
            'sequence' => (int) substr($number, (int) strrpos($number, '/') + 1),
            'number' => $number,
            'created_at' => now(),
        ]);

        return $record;
    }

    /** A tax breakdown in the shape Lunar stores: the order's is keyed by the tax rate, a line's is a list. */
    private function breakdown(int $amount, TaxRateAmount $rate, Currency $currency, bool $keyed): TaxBreakdown
    {
        $identifier = 'tax_rate_'.$rate->taxRate->id;

        $row = new TaxBreakdownAmount(
            price: new Price($amount, $currency, 1),
            identifier: $identifier,
            description: (string) $rate->taxRate->name,
            percentage: (float) self::VAT_PERCENT,
        );

        return new TaxBreakdown(collect($keyed ? [$identifier => $row] : [$row]));
    }

    /** The name and default address the store holds for the customer, as bill to and deliver to. */
    private function addresses(Order $order, Customer $customer, string $email): void
    {
        /** @var Address|null $address */
        $address = $customer->addresses()->orderByDesc('shipping_default')->orderBy('id')->first();

        foreach (['shipping', 'billing'] as $type) {
            $order->addresses()->create([
                'type' => $type,
                'title' => $address->title ?? $customer->title,
                'first_name' => $address->first_name ?? $customer->first_name,
                'last_name' => $address->last_name ?? $customer->last_name,
                'company_name' => $address->company_name ?? $customer->company_name,
                'line_one' => $address?->line_one,
                'line_two' => $address?->line_two,
                'line_three' => $address?->line_three,
                'city' => $address?->city,
                'state' => $address?->state,
                'postcode' => $address?->postcode,
                'country_id' => $address?->country_id,
                'contact_email' => $address->contact_email ?? $email,
                'contact_phone' => $address?->contact_phone,
                'tax_identifier' => $type === 'billing' ? $customer->tax_identifier : null,
                'created_at' => $order->placed_at,
                'updated_at' => $order->placed_at,
            ]);
        }
    }

    private function report(): void
    {
        if ($this->command === null) {
            return;
        }

        foreach ($this->notes as $note) {
            $this->command->line($note);
        }

        if ($this->problems !== []) {
            $this->command->newLine();
            $this->command->error(count($this->problems).' problem(s). Nothing was written.');

            foreach ($this->problems as $problem) {
                $this->command->line('  - '.$problem);
            }

            return;
        }

        $this->command->info(($this->dryRun ? '[check only, nothing written] ' : '').$this->imported.' invoice(s) '.($this->dryRun ? 'would be imported' : 'imported').' onto customer '.$this->customerId.'.');
    }
}
