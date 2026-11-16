<?php

declare(strict_types=1);

use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use Lunar\Models\Price;
use Tests\Support\VendorSync;

beforeEach(fn () => VendorSync::boot());

/**
 * A Safety Jogger product with one SKU per entry: [EU size, cost, cost currency, RRP, RRP currency].
 *
 * @param  list<array{0: string, 1: float, 2: ?string, 3: float, 4: ?string}>  $skus
 * @return array<string, mixed>
 */
function eurProduct(array $skus, int $id = 500100): array
{
    $detail = VendorSync::detail($id, 'EURBOOT', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022']);
    $template = $detail['colors'][0]['skus'][0];
    $detail['colors'][0]['skus'] = [];

    foreach ($skus as $index => [$size, $cost, $costCurrency, $rrp, $rrpCurrency]) {
        $detail['colors'][0]['skus'][] = [
            ...$template,
            'id' => $id * 100 + $index,
            'sizeRange' => $size,
            'eanCode' => (string) (6000000000000 + $id * 10 + $index),
            'customerPrice' => ['currency' => $costCurrency, 'value' => $cost],
            'rrpPrice' => ['currency' => $rrpCurrency, 'value' => $rrp],
        ];
    }

    return $detail;
}

function importEur(array $detail): mixed
{
    VendorSync::fakeHttp([['id' => $detail['id']]], [$detail['id'] => $detail]);

    return VendorSync::import('SJ');
}

it('imports a EUR priced SKU unpriced, never converted, and drafts the product', function (): void {
    $report = importEur(eurProduct([['42', 30.0, 'EUR', 58.9, 'EUR'], ['43', 30.0, 'EUR', 58.9, 'EUR']]));

    $product = VendorSync::product('SJ', '500100');
    $offer = VendorOffer::query()->orderBy('vendor_sku')->first();

    expect($product->status)->toBe('draft')
        ->and(VendorProduct::query()->sole()->draft_reasons)->toContain(DraftReason::NON_GBP_PRICE)
        ->and($product->variants)->toHaveCount(2)
        ->and(Price::query()->count())->toBe(0)
        ->and($offer->cost_price)->toBeNull()
        ->and($offer->sellable)->toBeFalse()
        ->and($product->variants->sum('stock'))->toBe(0)
        ->and($report->nonGbpVariants)->toBe(2)
        ->and($report->nonGbpProducts)->toBe(1)
        ->and($report->counts())->toMatchArray(['non_gbp_variants' => 2, 'non_gbp_products' => 1])
        ->and($report->tallies['non_gbp_currency'])->toBe(['EUR' => 2])
        ->and(implode("\n", $report->messages))->toContain('EURBOOT [500100]: 2 SKUs priced in EUR, not GBP; left unpriced and not converted, product drafted');
});

it('drafts the whole product when only some SKUs are non-GBP, and prices the GBP ones', function (): void {
    $report = importEur(eurProduct([['42', 20.0, 'GBP', 60.0, 'GBP'], ['43', 20.0, 'EUR', 60.0, 'EUR']]));

    expect(VendorSync::product('SJ', '500100')->status)->toBe('draft')
        ->and(Price::query()->count())->toBe(1)
        ->and(VendorOffer::query()->whereNull('cost_price')->count())->toBe(1)
        ->and($report->nonGbpVariants)->toBe(1);
});

it('treats a GBP cost with a non-GBP RRP as non-GBP', function (): void {
    $report = importEur(eurProduct([['42', 20.0, 'GBP', 60.0, 'USD']]));

    expect($report->tallies['non_gbp_currency'])->toBe(['USD' => 1])
        ->and(Price::query()->count())->toBe(0);
});

it('fails closed on a blank currency and counts it apart from an explicit one', function (): void {
    $report = importEur(eurProduct([['42', 20.0, '', 60.0, 'GBP'], ['43', 20.0, null, 60.0, 'GBP'], ['44', 20.0, 'EUR', 60.0, 'EUR']]));

    expect($report->tallies['non_gbp_currency'])->toBe(['(blank)' => 2, 'EUR' => 1])
        ->and($report->nonGbpVariants)->toBe(3);
});

it('reports blank and explicit currencies in a dry run without writing', function (): void {
    VendorSync::fakeHttp([['id' => 500100]], [500100 => eurProduct([['42', 20.0, '', 60.0, 'GBP'], ['43', 20.0, 'EUR', 60.0, 'EUR']])]);

    $report = VendorSync::import('SJ', dryRun: true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->tallies['non_gbp_currency'])->toBe(['(blank)' => 1, 'EUR' => 1])
        ->and($report->nonGbpVariants)->toBe(2)
        ->and(VendorProduct::query()->count())->toBe(0);
});

it('keeps a non-GBP product draft when its images arrive', function (): void {
    importEur(eurProduct([['42', 30.0, 'EUR', 58.9, 'EUR']]));
    VendorSync::syncImages(['500100']);

    $product = VendorSync::product('SJ', '500100');

    expect($product->getMedia('images'))->not->toBeEmpty()
        ->and($product->status)->toBe('draft')
        ->and(VendorProduct::query()->sole()->draft_reasons)->toBe([DraftReason::NON_GBP_PRICE]);
});

it('prices and publishes the product once the vendor supplies GBP, and unprices it again if EUR returns', function (): void {
    importEur(eurProduct([['42', 20.0, 'EUR', 60.0, 'EUR']]));
    VendorSync::syncImages(['500100']);

    expect(Price::query()->count())->toBe(0);

    importEur(eurProduct([['42', 20.0, 'GBP', 60.0, 'GBP']]));

    $product = VendorSync::product('SJ', '500100');
    $price = Price::query()->sole();

    expect($product->status)->toBe('published')
        ->and($price->getRawOriginal('price'))->toBe(3000)
        ->and($price->getRawOriginal('compare_price'))->toBe(5000)
        ->and(VendorOffer::query()->sole()->cost_price)->toBe(2000);

    // A price stored earlier must not survive a EUR payload.
    importEur(eurProduct([['42', 20.0, 'EUR', 60.0, 'EUR']]));

    expect(Price::query()->count())->toBe(0)
        ->and(VendorSync::product('SJ', '500100')->status)->toBe('draft')
        ->and(VendorOffer::query()->sole()->cost_price)->toBeNull();
});
