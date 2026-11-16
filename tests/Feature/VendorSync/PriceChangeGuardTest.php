<?php

declare(strict_types=1);

use App\Domain\VendorSync\Import\AcceptedPrices;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Notifications\SyncRunAlert;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Lunar\Models\Price;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
    config()->set('vendorsync.markup', '1.5');
    config()->set('vendorsync.vendors.SJ.markup', null);
    config()->set('vendorsync.price_change_guard_percent', 40);
});

/**
 * One GBP Safety Jogger product. SKU ids are 1 + the EU size (42 is 142).
 * RRP is 100.00 inc VAT unless given.
 *
 * @param  array<string, float>  $costs  EU size => cost
 * @return array<string, mixed>
 */
function guardProduct(array $costs, float $rrp = 100.0, int $id = 600100): array
{
    $detail = VendorSync::detail($id, 'GUARDBOOT', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022']);
    $template = $detail['colors'][0]['skus'][0];
    $detail['colors'][0]['skus'] = [];

    foreach ($costs as $size => $cost) {
        $detail['colors'][0]['skus'][] = [
            ...$template,
            'id' => 100 + (int) $size,
            'sizeRange' => (string) $size,
            'eanCode' => (string) (6100000000000 + (int) $size),
            'customerPrice' => ['currency' => 'GBP', 'value' => $cost],
            'rrpPrice' => ['currency' => 'GBP', 'value' => $rrp],
        ];
    }

    return $detail;
}

function guardImport(array $costs, float $rrp = 100.0, bool $dryRun = false, bool $force = false, ?AcceptedPrices $accepted = null): mixed
{
    VendorSync::fakeHttp([['id' => 600100]], [600100 => guardProduct($costs, $rrp)]);

    return VendorSync::import('SJ', $dryRun, $force, $accepted);
}

/**
 * @return array{cost: ?int, price: ?int, compare: ?int}
 */
function stored(string $size): array
{
    $offer = VendorOffer::query()->where('vendor_sku', (string) (100 + (int) $size))->sole();
    $price = Price::query()->where('priceable_id', $offer->product_variant_id)->first();

    return [
        'cost' => $offer->cost_price,
        'price' => $price?->getRawOriginal('price'),
        'compare' => $price?->getRawOriginal('compare_price'),
    ];
}

it('holds cost, price and compare price together as one unit, and lets the other SKUs through', function (): void {
    $first = guardImport(['42' => 10.0, '43' => 10.0]);

    // Creates are never guarded.
    expect($first->held)->toBe([])
        ->and(stored('42'))->toBe(['cost' => 1000, 'price' => 1500, 'compare' => 8333]);

    // 42: +50 percent, held. 43: +5 percent, applied.
    $report = guardImport(['42' => 15.0, '43' => 10.5]);

    expect($report->held)->toHaveCount(1)
        ->and($report->held[0]->toArray())->toBe([
            'sku' => '142',
            'vendor_product_id' => '600100',
            'product_id' => VendorSync::product('SJ', '600100')->id,
            'product' => 'GUARDBOOT',
            'old_cost' => 1000,
            'new_cost' => 1500,
            'old_price' => 1500,
            'new_price' => 2250,
            'old_compare_price' => 8333,
            'new_compare_price' => 8333,
            'percent' => 50.0,
        ])
        ->and($report->counts())->toMatchArray(['held_prices' => 1])
        // All three old values kept for the held SKU.
        ->and(stored('42'))->toBe(['cost' => 1000, 'price' => 1500, 'compare' => 8333])
        // The other SKU moved on.
        ->and(stored('43'))->toBe(['cost' => 1050, 'price' => 1575, 'compare' => 8333])
        ->and(implode("\n", $report->messages))->toContain('SJ GUARDBOOT [600100]: 1 SKU with a cost change of more than 40%; cost, price and compare price kept (SKUs: 142');
});

it('lets exactly 40 percent through and holds anything above it, up or down', function (string $size, float $new, bool $held): void {
    guardImport([$size => 10.0]);
    $report = guardImport([$size => $new]);

    expect(count($report->held) === 1)->toBe($held)
        ->and(stored($size)['cost'])->toBe($held ? 1000 : (int) round($new * 100));
})->with([
    'up, exactly 40' => ['42', 14.0, false],
    'up, a penny over' => ['42', 14.01, true],
    'down, exactly 40' => ['42', 6.0, false],
    'down, a penny over' => ['42', 5.99, true],
    'down, to a third' => ['42', 3.33, true],
]);

it('is never triggered by a markup change, whatever it does to the price', function (): void {
    guardImport(['42' => 10.0]);

    // 1.5 to 5.0 is +233 percent on the price; the cost has not moved.
    config()->set('vendorsync.vendors.SJ.markup', '5');
    $report = guardImport(['42' => 10.0], force: true);

    expect($report->held)->toBe([])
        ->and(stored('42'))->toBe(['cost' => 1000, 'price' => 5000, 'compare' => 8333]);
});

it('looks at the cost again on the next run, so a held change is reported until it is accepted', function (): void {
    guardImport(['42' => 10.0]);
    guardImport(['42' => 20.0]);

    // The same payload again: not "unchanged", because the hash did not advance.
    $report = guardImport(['42' => 20.0]);

    expect($report->unchanged)->toBe(0)
        ->and($report->held)->toHaveCount(1)
        ->and(stored('42')['cost'])->toBe(1000);
});

it('accepts a held change for the named SKU only', function (): void {
    guardImport(['42' => 10.0, '43' => 10.0]);

    $report = guardImport(['42' => 20.0, '43' => 20.0], accepted: new AcceptedPrices(skus: ['142']));

    expect($report->held)->toHaveCount(1)
        ->and($report->held[0]->vendorSku)->toBe('143')
        ->and(stored('42'))->toBe(['cost' => 2000, 'price' => 3000, 'compare' => 8333])
        ->and(stored('43')['cost'])->toBe(1000);
});

it('accepts every held change of a named product', function (): void {
    guardImport(['42' => 10.0, '43' => 10.0]);

    $report = guardImport(['42' => 20.0, '43' => 20.0], accepted: new AcceptedPrices(vendorProductIds: ['600100']));

    expect($report->held)->toBe([])
        ->and(stored('42')['cost'])->toBe(2000)
        ->and(stored('43')['cost'])->toBe(2000);

    // Accepted, so the hash advances and the product is quiet next time.
    expect(guardImport(['42' => 20.0, '43' => 20.0])->unchanged)->toBe(1);
});

it('does not guard a product drafted cost_at_or_above_rrp, or one that was last time', function (): void {
    guardImport(['42' => 10.0]);

    // Cost 100.00 against an RRP of 83.33 ex VAT: every variant at or above its RRP.
    $report = guardImport(['42' => 100.0]);

    expect($report->held)->toBe([])
        ->and(VendorProduct::query()->sole()->draft_reasons)->toContain(DraftReason::COST_AT_OR_ABOVE_RRP)
        ->and(stored('42')['cost'])->toBe(10000);

    // Back to a sane cost: a 90 percent drop, but the product was in review last time.
    $report = guardImport(['42' => 10.0]);

    expect($report->held)->toBe([])
        ->and(stored('42')['cost'])->toBe(1000);
});

it('does guard a product that is only waiting for its first image', function (): void {
    guardImport(['42' => 10.0]);

    expect(VendorProduct::query()->sole()->draft_reasons)->toBe([DraftReason::NO_IMAGE])
        ->and(guardImport(['42' => 20.0])->held)->toHaveCount(1);
});

it('can be switched off with a threshold of zero', function (): void {
    config()->set('vendorsync.price_change_guard_percent', 0);

    guardImport(['42' => 10.0]);

    expect(guardImport(['42' => 90.0], rrp: 400.0)->held)->toBe([])
        ->and(stored('42')['cost'])->toBe(9000);
});

it('reports what a dry run would hold, and writes nothing', function (): void {
    guardImport(['42' => 10.0]);

    $report = guardImport(['42' => 20.0], dryRun: true);

    expect($report->held)->toHaveCount(1)
        ->and(implode("\n", $report->messages))->toContain('would be kept')
        ->and(stored('42')['cost'])->toBe(1000)
        ->and(json_decode((string) file_get_contents($report->reportPath), true)['held_prices'])->toHaveCount(1);
});

it('writes the full held list to the run report, records its path and its count, and mails the count', function (): void {
    Notification::fake();

    guardImport(['42' => 10.0, '43' => 10.0, '44' => 10.0]);
    $report = guardImport(['42' => 20.0, '43' => 21.0, '44' => 22.0]);

    $run = SyncRun::query()->latest('id')->first();
    $file = json_decode((string) File::get($run->report_path), true);

    expect($report->held)->toHaveCount(3)
        ->and($run->status)->toBe('succeeded')
        ->and($run->held_prices)->toBe(3)
        ->and($file['held_prices'])->toHaveCount(3)
        ->and(array_keys($file['held_prices'][0]))->toBe([
            'sku', 'vendor_product_id', 'product_id', 'product', 'old_cost', 'new_cost', 'old_price', 'new_price',
            'old_compare_price', 'new_compare_price', 'percent',
        ]);

    Notification::assertSentTo(
        new AnonymousNotifiable,
        SyncRunAlert::class,
        function (SyncRunAlert $alert) use ($run): bool {
            $mail = $alert->toMail(new AnonymousNotifiable);

            return $alert->run->is($run)
                && str_contains($mail->subject, 'held 3 price changes')
                && str_contains(implode("\n", $mail->introLines), 'Held price changes: 3.');
        },
    );
});

describe('accepting from the command line', function (): void {
    beforeEach(function (): void {
        guardImport(['42' => 10.0, '43' => 10.0]);
        VendorSync::fakeHttp([['id' => 600100]], [600100 => guardProduct(['42' => 20.0, '43' => 20.0])]);
    });

    it('refuses a bare --accept-price-changes and runs nothing', function (): void {
        $runs = SyncRun::query()->count();

        $this->artisan('vendorsync:catalogue SJ --without-images --accept-price-changes')
            ->expectsOutputToContain('A bare --accept-price-changes is refused')
            ->assertFailed();

        expect(SyncRun::query()->count())->toBe($runs)
            ->and(stored('42')['cost'])->toBe(1000);
    });

    it('accepts everything with --accept-price-changes --all', function (): void {
        $this->artisan('vendorsync:catalogue SJ --without-images --accept-price-changes --all')->assertSuccessful();

        expect(stored('42')['cost'])->toBe(2000)
            ->and(stored('43')['cost'])->toBe(2000);
    });

    it('accepts named SKUs only', function (): void {
        $this->artisan('vendorsync:catalogue SJ --without-images --accept-price-changes=143')->assertSuccessful();

        expect(stored('42')['cost'])->toBe(1000)
            ->and(stored('43')['cost'])->toBe(2000);
    });

    it('accepts a named product', function (): void {
        $this->artisan('vendorsync:catalogue SJ --without-images --accept-product=600100')->assertSuccessful();

        expect(stored('42')['cost'])->toBe(2000);
    });

    it('refuses --all on its own, or together with named SKUs', function (string $arguments): void {
        $this->artisan("vendorsync:catalogue SJ --without-images {$arguments}")
            ->expectsOutputToContain('--all only works with a bare --accept-price-changes')
            ->assertFailed();

        expect(stored('42')['cost'])->toBe(1000);
    })->with(['--all', '--accept-price-changes=142 --all', '--accept-price-changes --accept-product=600100 --all']);

    it('refuses an empty --accept-product', function (): void {
        $this->artisan('vendorsync:catalogue SJ --without-images --accept-product=')
            ->expectsOutputToContain('--accept-product needs a vendor product ID')
            ->assertFailed();
    });

    it('shows held prices in the output', function (): void {
        $this->artisan('vendorsync:catalogue SJ --without-images')
            ->expectsOutputToContain('2 price changes held for review')
            ->expectsOutputToContain('cost 10.00 to 20.00 (+100.0%), price 15.00 to 30.00; held')
            ->assertSuccessful();
    });
});
