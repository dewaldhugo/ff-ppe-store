<?php

declare(strict_types=1);

use App\Domain\VendorSync\Import\AcceptedPrices;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Import\RunReport;
use App\Domain\VendorSync\Import\StockSync;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Notifications\SyncRunAlert;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
    config()->set('vendorsync.markup', '1.5');
    config()->set('vendorsync.vendors.SJ.markup', null);
    config()->set('vendorsync.price_change_guard_percent', 40);
});

/**
 * One Safety Jogger product, one SKU per entry: size => [cost, currency, stock].
 * RRP is 60.00 in the same currency as the cost. SKU ids are 700000 + the EU size.
 *
 * @param  array<string, array{0: float, 1: string, 2?: int}>  $skus
 * @return array<string, mixed>
 */
function currencyProduct(array $skus, int $id = 700100): array
{
    $detail = VendorSync::detail($id, 'FLIPBOOT', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022']);
    $template = $detail['colors'][0]['skus'][0];
    $detail['colors'][0]['skus'] = [];

    foreach ($skus as $size => $sku) {
        [$cost, $currency] = $sku;

        $detail['colors'][0]['skus'][] = [
            ...$template,
            'id' => 700000 + (int) $size,
            'sizeRange' => (string) $size,
            'eanCode' => (string) (6200000000000 + (int) $size),
            'stock' => $sku[2] ?? 10,
            'customerPrice' => ['currency' => $currency, 'value' => $cost],
            'rrpPrice' => ['currency' => $currency, 'value' => 60.0],
        ];
    }

    return $detail;
}

function importCurrency(array $skus, bool $dryRun = false, ?AcceptedPrices $accepted = null): mixed
{
    VendorSync::fakeHttp([['id' => 700100]], [700100 => currencyProduct($skus)]);

    return VendorSync::import('SJ', $dryRun, accepted: $accepted);
}

/**
 * @return array{cost: ?int, price: ?int, compare: ?int, sellable: bool, stock: int, offer_stock: int}
 */
function storedSku(string $size): array
{
    $offer = VendorOffer::query()->where('vendor_sku', (string) (700000 + (int) $size))->sole();
    $price = Price::query()->where('priceable_id', $offer->product_variant_id)->first();

    return [
        'cost' => $offer->cost_price,
        'price' => $price?->getRawOriginal('price'),
        'compare' => $price?->getRawOriginal('compare_price'),
        'sellable' => (bool) $offer->sellable,
        'stock' => (int) ProductVariant::query()->findOrFail($offer->product_variant_id)->stock,
        'offer_stock' => (int) $offer->stock,
    ];
}

it('keeps stored GBP cost, price and compare price, and the published status, when the response is EUR', function (): void {
    importCurrency(['42' => [20.0, 'GBP'], '43' => [20.0, 'GBP']]);
    VendorSync::syncImages(['700100']);

    $hash = VendorProduct::query()->sole()->payload_hash;

    expect(VendorSync::product('SJ', '700100')->status)->toBe('published')
        ->and(storedSku('42'))->toMatchArray(['cost' => 2000, 'price' => 3000, 'compare' => 5000, 'sellable' => true]);

    $report = importCurrency(['42' => [20.0, 'EUR'], '43' => [20.0, 'EUR']]);

    expect(storedSku('42'))->toMatchArray(['cost' => 2000, 'price' => 3000, 'compare' => 5000, 'sellable' => true])
        ->and(storedSku('43'))->toMatchArray(['cost' => 2000, 'price' => 3000, 'compare' => 5000, 'sellable' => true])
        ->and(Price::query()->count())->toBe(2)
        ->and(VendorSync::product('SJ', '700100')->status)->toBe('published')
        ->and(VendorProduct::query()->sole()->draft_reasons)->toBe([])
        // The hash is not advanced, so the next run looks at the product again.
        ->and(VendorProduct::query()->sole()->payload_hash)->toBe($hash)
        ->and($report->heldCurrency)->toHaveCount(2)
        ->and($report->counts())->toMatchArray(['held_currency' => 2, 'non_gbp_variants' => 0, 'non_gbp_products' => 0])
        ->and($report->tallies['held_currency'])->toBe(['EUR' => 2])
        ->and(implode("\n", $report->messages))->toContain('FLIPBOOT [700100]: 2 SKUs came back priced in EUR, not GBP; the stored GBP cost, price, compare price and availability were kept');
});

it('reports each held SKU with its stored values and when the stored GBP values were last written', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);

    $offer = VendorOffer::query()->sole();
    $written = now()->subDays(9)->startOfSecond();

    Price::query()->where('priceable_id', $offer->product_variant_id)->update(['updated_at' => $written]);

    $report = importCurrency(['42' => [20.0, 'EUR']]);
    $held = $report->heldCurrency[0]->toArray();
    $file = json_decode((string) File::get(SyncRun::query()->latest('id')->first()->report_path), true);

    expect($held)->toMatchArray([
        'sku' => '700042',
        'vendor_product_id' => '700100',
        'product' => 'FLIPBOOT',
        'currency_seen' => 'EUR',
        'stored_cost' => 2000,
        'stored_price' => 3000,
        'stored_compare_price' => 5000,
        'stored_price_written_at' => $written->timezone('Europe/London')->format('Y-m-d H:i:s'),
    ])
        ->and($held['offer_updated_at'])->not->toBeNull()
        ->and($file['held_currency'])->toHaveCount(1)
        ->and($file['held_currency'][0]['stored_price_written_at'])->toBe($held['stored_price_written_at'])
        ->and($report->heldCurrency[0]->line('SJ'))->toContain('last written '.$held['stored_price_written_at']);
});

it('still applies stock quantity and availability from the response while the price is held', function (): void {
    // Catalogue-owned stock: the response's stock figure is applied to the
    // variant and the offer, with the stored availability.
    config()->set('vendorsync.vendors.SJ.stock_authority', 'catalogue');

    importCurrency(['42' => [20.0, 'GBP', 10], '43' => [20.0, 'GBP', 10]]);

    $report = importCurrency(['42' => [20.0, 'EUR', 4], '43' => [20.0, 'EUR', 0]]);

    expect($report->heldCurrency)->toHaveCount(2)
        ->and(storedSku('42'))->toMatchArray(['stock' => 4, 'offer_stock' => 4, 'sellable' => true, 'price' => 3000])
        ->and(storedSku('43'))->toMatchArray(['stock' => 0, 'offer_stock' => 0, 'sellable' => true, 'price' => 3000]);

    // And it is back in stock when the response says so, still on the stored price.
    importCurrency(['42' => [20.0, 'EUR', 7], '43' => [20.0, 'EUR', 3]]);

    expect(storedSku('42'))->toMatchArray(['stock' => 7, 'sellable' => true, 'price' => 3000])
        ->and(storedSku('43'))->toMatchArray(['stock' => 3, 'sellable' => true, 'price' => 3000]);
});

it('does not freeze the 15 minute stock feed for a held variant', function (): void {
    importCurrency(['42' => [20.0, 'GBP', 10]]);
    importCurrency(['42' => [20.0, 'EUR', 10]]);

    expect(storedSku('42'))->toMatchArray(['stock' => 10, 'sellable' => true, 'price' => 3000]);

    app(StockSync::class)->apply(Vendor::query()->where('code', 'SJ')->sole(), ['700042' => 3], new RunReport('SJ', 'stock'));

    expect(storedSku('42'))->toMatchArray(['stock' => 3, 'offer_stock' => 3, 'sellable' => true, 'price' => 3000]);
});

it('applies the GBP SKUs of a product and holds only its EUR SKUs', function (): void {
    importCurrency(['42' => [20.0, 'GBP'], '43' => [20.0, 'GBP']]);
    VendorSync::syncImages(['700100']);

    $report = importCurrency(['42' => [21.0, 'GBP'], '43' => [20.0, 'EUR']]);

    expect(storedSku('42'))->toMatchArray(['cost' => 2100, 'price' => 3150])
        ->and(storedSku('43'))->toMatchArray(['cost' => 2000, 'price' => 3000])
        ->and($report->heldCurrency)->toHaveCount(1)
        ->and(VendorSync::product('SJ', '700100')->status)->toBe('published');
});

it('drafts non_gbp_price only for a variant with no stored GBP price', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);
    VendorSync::syncImages(['700100']);

    // A new SKU arrives priced in EUR: nothing stored to keep, so it is unpriced
    // and the product is drafted. The stored SKU is still held.
    $report = importCurrency(['42' => [20.0, 'EUR'], '43' => [20.0, 'EUR']]);

    expect($report->heldCurrency)->toHaveCount(1)
        ->and($report->nonGbpVariants)->toBe(1)
        ->and(storedSku('42'))->toMatchArray(['price' => 3000, 'cost' => 2000])
        ->and(storedSku('43'))->toMatchArray(['price' => null, 'cost' => null, 'sellable' => false])
        ->and(VendorProduct::query()->sole()->draft_reasons)->toContain(DraftReason::NON_GBP_PRICE)
        ->and(VendorSync::product('SJ', '700100')->status)->toBe('draft');
});

it('still drafts and leaves unpriced a first import that is EUR, as before', function (): void {
    $report = importCurrency(['42' => [20.0, 'EUR']]);

    expect($report->heldCurrency)->toBe([])
        ->and(Price::query()->count())->toBe(0)
        ->and(VendorProduct::query()->sole()->draft_reasons)->toContain(DraftReason::NON_GBP_PRICE)
        ->and($report->nonGbpVariants)->toBe(1);
});

it('does not let a held EUR SKU switch the 40 percent guard off for the other SKUs, and never compares EUR with GBP', function (): void {
    importCurrency(['42' => [20.0, 'GBP'], '43' => [20.0, 'GBP']]);

    // 42 is EUR at 90.00 (a 350 percent "change" if it were compared). 43 really moves by 100 percent.
    $report = importCurrency(['42' => [90.0, 'EUR'], '43' => [40.0, 'GBP']]);

    expect($report->heldCurrency)->toHaveCount(1)
        ->and($report->held)->toHaveCount(1)
        ->and($report->held[0]->vendorSku)->toBe('700043')
        ->and(storedSku('42'))->toMatchArray(['cost' => 2000, 'price' => 3000])
        ->and(storedSku('43'))->toMatchArray(['cost' => 2000, 'price' => 3000]);
});

it('compares a returning GBP price with the last known good cost', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);
    importCurrency(['42' => [20.0, 'EUR']]);

    $report = importCurrency(['42' => [20.4, 'GBP']]);

    expect($report->held)->toBe([])
        ->and($report->heldCurrency)->toBe([])
        ->and(storedSku('42'))->toMatchArray(['cost' => 2040, 'price' => 3060]);

    importCurrency(['42' => [20.0, 'EUR']]);
    $report = importCurrency(['42' => [40.0, 'GBP']]);

    expect($report->held)->toHaveCount(1)
        ->and(storedSku('42'))->toMatchArray(['cost' => 2040, 'price' => 3060]);
});

it('retries a held flip on the next run and applies the GBP price when it returns', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);

    $first = importCurrency(['42' => [20.0, 'EUR']]);
    $second = importCurrency(['42' => [20.0, 'EUR']]);

    // Still held and reported again: the hash was not advanced, so it is not "unchanged".
    expect($first->heldCurrency)->toHaveCount(1)
        ->and($second->heldCurrency)->toHaveCount(1)
        ->and($second->unchanged)->toBe(0);

    $third = importCurrency(['42' => [22.0, 'GBP']]);

    expect($third->heldCurrency)->toBe([])
        ->and(storedSku('42'))->toMatchArray(['cost' => 2200, 'price' => 3300]);
});

it('shows in a dry run what would be held, and writes nothing', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);

    $hash = VendorProduct::query()->sole()->payload_hash;
    $report = importCurrency(['42' => [20.0, 'EUR']], dryRun: true);
    $file = json_decode((string) File::get($report->reportPath), true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->heldCurrency)->toHaveCount(1)
        ->and($report->nonGbpVariants)->toBe(0)
        ->and(implode("\n", $report->messages))->toContain('would be kept')
        ->and($file['held_currency'])->toHaveCount(1)
        ->and($file['held_currency'][0])->toHaveKey('stored_price_written_at')
        ->and(storedSku('42'))->toMatchArray(['cost' => 2000, 'price' => 3000])
        ->and(VendorProduct::query()->sole()->payload_hash)->toBe($hash);
});

it('records the held count and the report path on the run, and mails both', function (): void {
    Notification::fake();

    importCurrency(['42' => [20.0, 'GBP'], '43' => [20.0, 'GBP']]);
    $report = importCurrency(['42' => [20.0, 'EUR'], '43' => [20.0, 'EUR']]);

    $run = SyncRun::query()->latest('id')->first();
    $file = json_decode((string) File::get($run->report_path), true);

    expect($report->heldCurrency)->toHaveCount(2)
        ->and($run->status)->toBe('succeeded')
        ->and($run->held_currency)->toBe(2)
        ->and($file['counts']['held_currency'])->toBe(2);

    Notification::assertSentTo(
        new AnonymousNotifiable,
        SyncRunAlert::class,
        function (SyncRunAlert $alert) use ($run): bool {
            $mail = $alert->toMail(new AnonymousNotifiable);
            $lines = implode("\n", $mail->introLines);

            return $alert->run->is($run)
                && str_contains($mail->subject, 'held 2 currency flips')
                && str_contains($lines, 'Held currency flips: 2.')
                && str_contains($lines, $run->report_path);
        },
    );
});

// ---------------------------------------------------------------------------
// Re-fetching and diagnostics
// ---------------------------------------------------------------------------

/**
 * Import with the product answering successive requests with successive answers.
 *
 * @param  list<mixed>  $answers  Details, or VendorSync::reply() answers.
 */
function importSequence(array $answers, bool $dryRun = false): mixed
{
    VendorSync::fakeHttp([['id' => 700100]], []);
    VendorSync::sequence(700100, $answers);

    return VendorSync::import('SJ', $dryRun);
}

function sleptSeconds(int $seconds, int $times = 1): void
{
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === $seconds && $duration->totalSeconds >= 1, $times);
}

it('re-fetches a EUR product twice, then keeps the stored GBP price, reports the flip and leaves the hash alone', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);
    VendorSync::syncImages(['700100']);

    $hash = VendorProduct::query()->sole()->payload_hash;
    $report = importSequence([currencyProduct(['42' => [20.0, 'EUR']])]);
    $run = SyncRun::query()->latest('id')->first();

    expect(VendorSync::productRequests(700100))->toBe(3)
        ->and($report->currencyRefetches)->toBe(2)
        ->and($report->currencyRecovered)->toBe(0)
        ->and($report->heldCurrency)->toHaveCount(1)
        ->and($run->stats)->toMatchArray(['currency_refetches' => 2, 'currency_recovered' => 0, 'response_retries' => 0, 'response_recovered' => 0])
        ->and(storedSku('42'))->toMatchArray(['cost' => 2000, 'price' => 3000, 'compare' => 5000])
        ->and(VendorSync::product('SJ', '700100')->status)->toBe('published')
        ->and(VendorProduct::query()->sole()->payload_hash)->toBe($hash);

    // The first re-fetch waits 2 seconds, the second 10.
    sleptSeconds(2);
    sleptSeconds(10);
});

it('uses the first fully GBP response of a re-fetch and prices the product normally', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);
    VendorSync::syncImages(['700100']);

    $hash = VendorProduct::query()->sole()->payload_hash;
    $report = importSequence([currencyProduct(['42' => [20.0, 'EUR']]), currencyProduct(['42' => [22.0, 'GBP']]), currencyProduct(['42' => [99.0, 'GBP']])]);

    expect(VendorSync::productRequests(700100))->toBe(2)
        ->and($report->currencyRefetches)->toBe(1)
        ->and($report->currencyRecovered)->toBe(1)
        ->and($report->heldCurrency)->toBe([])
        ->and($report->currencyDiagnostics)->toBe([])
        ->and(storedSku('42'))->toMatchArray(['cost' => 2200, 'price' => 3300])
        ->and(VendorSync::product('SJ', '700100')->status)->toBe('published')
        ->and(VendorProduct::query()->sole()->payload_hash)->not->toBe($hash);

    sleptSeconds(2);
    sleptSeconds(10, 0);
});

it('drafts non_gbp_price when a product never answers in GBP and nothing is stored, and records diagnostics', function (): void {
    $report = importSequence([currencyProduct(['42' => [20.0, 'EUR']])]);

    expect(VendorSync::productRequests(700100))->toBe(3)
        ->and(Price::query()->count())->toBe(0)
        ->and(VendorProduct::query()->sole()->draft_reasons)->toContain(DraftReason::NON_GBP_PRICE)
        ->and($report->heldCurrency)->toBe([])
        ->and($report->currencyDiagnostics)->toHaveCount(1)
        ->and($report->currencyDiagnostics[0])->toMatchArray(['vendor_product_id' => '700100', 'outcome' => 'unpriced_non_gbp_price', 'held_skus' => 0, 'unpriced_skus' => 1])
        ->and($report->currencyDiagnostics[0]['attempts'])->toHaveCount(3);
});

it('retries an unexpected response and uses the recovered one', function (): void {
    $report = importSequence([VendorSync::reply('<html>Bad gateway page</html>'), VendorSync::reply(['unexpected', 'list']), currencyProduct(['42' => [20.0, 'GBP']])]);

    expect(VendorSync::productRequests(700100))->toBe(3)
        ->and($report->responseRetries)->toBe(2)
        ->and($report->responseRecovered)->toBe(1)
        ->and($report->errors)->toBe(0)
        ->and(storedSku('42'))->toMatchArray(['cost' => 2000, 'price' => 3000]);

    sleptSeconds(2);
    sleptSeconds(10);
});

it('reports an unexpected response that persists, and does not lose the stored product', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);
    VendorSync::syncImages(['700100']);

    $report = importSequence([VendorSync::reply('<html>Bad gateway page</html>')]);

    expect(VendorSync::productRequests(700100))->toBe(3)
        ->and($report->responseRetries)->toBe(2)
        ->and($report->responseRecovered)->toBe(0)
        ->and($report->errors)->toBe(1)
        ->and(implode("\n", $report->messages))->toContain('Safety Jogger API GET /product/700100 returned an unexpected response. (after 3 attempts)')
        ->and(VendorProduct::query()->sole()->missed_runs)->toBe(0)
        ->and(VendorProduct::query()->sole()->draft_reasons)->toBe([])
        ->and(storedSku('42'))->toMatchArray(['cost' => 2000, 'price' => 3000]);
});

it('does not retry a status error, which the API client handles itself', function (): void {
    $report = importSequence([VendorSync::reply(['error' => 'gone'], 404)]);

    expect(VendorSync::productRequests(700100))->toBe(1)
        ->and($report->errors)->toBe(1)
        ->and($report->responseRetries)->toBe(0);
});

it('records the currency fields of every attempt and only allow-listed response headers, never credentials', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);

    $headers = [
        'Date' => 'Mon, 16 Nov 2026 02:15:01 GMT',
        'Server' => 'nginx',
        'Via' => '1.1 varnish',
        'Age' => '12',
        'X-Cache' => 'HIT',
        'X-Request-Id' => 'req-abc-1',
        'X-Region' => 'eu-west-1',
        'Content-Type' => 'application/json',
        'Set-Cookie' => 'session=cookie-value-1',
        'X-Auth-Secret' => 'secret-value-2',
        'Authorization' => 'Bearer bearer-value-3',
        'X-Api-Key' => 'key-value-4',
    ];

    $usd = currencyProduct(['42' => [20.0, 'USD']]);
    $blank = currencyProduct(['42' => [20.0, '']]);

    importSequence([
        VendorSync::reply(currencyProduct(['42' => [20.0, 'EUR']]), 200, $headers),
        VendorSync::reply($usd, 200, [...$headers, 'X-Request-Id' => 'req-abc-2']),
        VendorSync::reply($blank, 200, [...$headers, 'X-Request-Id' => 'req-abc-3']),
    ]);

    $run = SyncRun::query()->latest('id')->first();
    $raw = (string) File::get($run->report_path);
    $file = json_decode($raw, true);
    $attempts = $file['currency_diagnostics'][0]['attempts'];

    expect($attempts)->toHaveCount(3)
        ->and(array_column($attempts, 'result'))->toBe(['non_gbp', 'non_gbp', 'non_gbp'])
        ->and($attempts[0]['currencies_seen'])->toBe(['cost' => ['EUR' => 1], 'rrp' => ['EUR' => 1]])
        ->and($attempts[1]['currencies_seen'])->toBe(['cost' => ['USD' => 1], 'rrp' => ['USD' => 1]])
        ->and($attempts[2]['currencies_seen'])->toBe(['cost' => ['(blank)' => 1], 'rrp' => ['(blank)' => 1]])
        ->and($attempts[0]['non_gbp_skus'][0])->toBe(['sku' => '700042', 'issue' => 'EUR', 'cost_currency' => 'EUR', 'rrp_currency' => 'EUR'])
        ->and($attempts[0]['status'])->toBe(200)
        ->and(array_keys($attempts[0]['response_headers']))->toBe(['age', 'date', 'server', 'via', 'x-cache', 'x-region', 'x-request-id'])
        ->and(array_column(array_column($attempts, 'response_headers'), 'x-request-id'))->toBe(['req-abc-1', 'req-abc-2', 'req-abc-3']);

    foreach (['cookie-value-1', 'secret-value-2', 'bearer-value-3', 'key-value-4', 'test-hash', 'test-password', 'tester@example.test'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }
});

it('does not store the headers of a request, whatever they carry', function (): void {
    // The client sends `username` and `secret` headers on every request.
    importSequence([currencyProduct(['42' => [20.0, 'EUR']])]);

    $file = (string) File::get(SyncRun::query()->latest('id')->first()->report_path);

    expect($file)->not->toContain('"username"')
        ->and($file)->not->toContain('"secret"')
        ->and($file)->not->toContain('test-hash');
});

it('waits for the configured delays and never re-fetches more than twice', function (): void {
    config()->set('vendorsync.safety_jogger.refetch_delay_seconds', 5);
    config()->set('vendorsync.safety_jogger.refetch_second_delay_seconds', 20);

    importSequence([currencyProduct(['42' => [20.0, 'EUR']])]);

    expect(VendorSync::productRequests(700100))->toBe(3);

    sleptSeconds(5);
    sleptSeconds(20);

    // Asking for more than 2 is clamped to 2; asking for 1 is honoured.
    config()->set('vendorsync.safety_jogger.refetches', 9);
    importSequence([currencyProduct(['42' => [20.0, 'EUR']])]);

    expect(VendorSync::productRequests(700100))->toBe(3);

    config()->set('vendorsync.safety_jogger.refetches', 1);
    importSequence([currencyProduct(['42' => [20.0, 'EUR']])]);

    expect(VendorSync::productRequests(700100))->toBe(2);
});

it('does not re-fetch a product that is out of scope', function (): void {
    $detail = currencyProduct(['42' => [20.0, 'EUR']]);
    // Not on the article_types list at all: still out of scope on its own,
    // unlike workwear, gloves and the rest, which no longer need a norm.
    $detail['articletype'] = [['name' => 'promotional', 'value' => 'Promotional']];

    $report = importSequence([$detail]);

    expect(VendorSync::productRequests(700100))->toBe(1)
        ->and($report->currencyRefetches)->toBe(0)
        ->and($report->outOfScope)->toBe(1);
});

it('retries a held flip on the next run, in full, and reports it again', function (): void {
    importCurrency(['42' => [20.0, 'GBP']]);

    $hash = VendorProduct::query()->sole()->payload_hash;
    $first = importSequence([currencyProduct(['42' => [20.0, 'EUR']])]);
    $second = importSequence([currencyProduct(['42' => [20.0, 'EUR']])]);

    expect(VendorSync::productRequests(700100))->toBe(3)
        ->and($first->heldCurrency)->toHaveCount(1)
        ->and($second->heldCurrency)->toHaveCount(1)
        ->and($second->unchanged)->toBe(0)
        ->and(VendorProduct::query()->sole()->payload_hash)->toBe($hash);

    $third = importSequence([currencyProduct(['42' => [20.0, 'EUR']]), currencyProduct(['42' => [23.0, 'GBP']])]);

    expect($third->currencyRecovered)->toBe(1)
        ->and($third->heldCurrency)->toBe([])
        ->and(storedSku('42'))->toMatchArray(['cost' => 2300, 'price' => 3450])
        ->and(VendorProduct::query()->sole()->payload_hash)->not->toBe($hash);
});

it('shows recoveries and held flips in a dry run without writing', function (): void {
    importCurrency(['42' => [20.0, 'GBP'], '43' => [20.0, 'GBP']]);

    $hash = VendorProduct::query()->sole()->payload_hash;
    $runs = SyncRun::query()->count();

    $recovered = importSequence([currencyProduct(['42' => [20.0, 'EUR'], '43' => [20.0, 'GBP']]), currencyProduct(['42' => [24.0, 'GBP'], '43' => [20.0, 'GBP']])], dryRun: true);

    expect($recovered->dryRun)->toBeTrue()
        ->and($recovered->currencyRecovered)->toBe(1)
        ->and($recovered->heldCurrency)->toBe([]);

    $held = importSequence([currencyProduct(['42' => [20.0, 'EUR'], '43' => [20.0, 'GBP']])], dryRun: true);
    $file = json_decode((string) File::get($held->reportPath), true);

    expect($held->heldCurrency)->toHaveCount(1)
        ->and($held->currencyDiagnostics)->toHaveCount(1)
        ->and($held->currencyDiagnostics[0]['outcome'])->toBe('held')
        ->and($file['held_currency'])->toHaveCount(1)
        ->and($file['currency']['currency_refetches'])->toBe(2)
        ->and($file['currency_diagnostics'])->toHaveCount(1)
        ->and(storedSku('42'))->toMatchArray(['cost' => 2000, 'price' => 3000])
        ->and(VendorProduct::query()->sole()->payload_hash)->toBe($hash)
        ->and(SyncRun::query()->count())->toBe($runs);
});

it('prints the currency summary and the still non-GBP products from the command', function (): void {
    VendorSync::fakeHttp([['id' => 700100]], []);
    VendorSync::sequence(700100, [currencyProduct(['42' => [20.0, 'EUR']])]);

    $this->artisan('vendorsync:catalogue', ['vendor' => 'SJ', '--dry-run' => true])
        ->expectsOutputToContain('Currency: 2 re-fetches, 0 products recovered (a re-fetch returned GBP), 1 still not in GBP.')
        ->expectsOutputToContain('FLIPBOOT [700100]: unpriced non gbp price, 3 attempts')
        ->assertExitCode(0);
});
