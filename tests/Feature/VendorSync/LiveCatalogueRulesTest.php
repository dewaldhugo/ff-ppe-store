<?php

declare(strict_types=1);

use App\Domain\Catalogue\Features\FeatureLabels;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Support\Carbon;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\Support\VendorSync;

/*
 * Rules that came out of the first dry run against the live Safety Jogger
 * catalogue.
 */

beforeEach(function (): void {
    VendorSync::boot();
});

/**
 * A detail with one SKU per given EU size.
 *
 * @param  list<string>  $sizes
 * @return array<string, mixed>
 */
function sjWithSizes(int $id, string $name, array $sizes, string $model = 'low'): array
{
    $detail = VendorSync::detail($id, $name, 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022'], model: $model);
    $sku = $detail['colors'][0]['skus'][0];
    $detail['colors'][0]['skus'] = [];

    foreach ($sizes as $index => $size) {
        $detail['colors'][0]['skus'][] = [...$sku, 'id' => $id * 100 + $index, 'sizeRange' => $size, 'eanCode' => '6000'.$id.$index];
    }

    return $detail;
}

function ukSizeOfSku(string $sku): string
{
    return ProductVariant::query()->where('sku', $sku)->sole()->values->firstWhere('option.handle', 'shoe_size')->translate('name');
}

it('uses the commercial name exactly as supplied', function (string $supplied, string $expected, string $slug): void {
    $detail = VendorSync::detail(1, 'IGNORED', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022']);
    $detail['commercialName'] = $supplied;
    VendorSync::fakeHttp([['id' => 1]], [1 => $detail]);

    VendorSync::import('SJ');

    $product = VendorSync::product('SJ', '1');

    expect($product->translateAttribute('name'))->toBe($expected)
        ->and($product->defaultUrl->slug)->toBe($slug);
})->with([
    ['BESTBOOT', 'BESTBOOT', 'safety-jogger-bestboot'],
    ['BESTBOOT2', 'BESTBOOT2', 'safety-jogger-bestboot2'],
    ['ACDC', 'ACDC', 'safety-jogger-acdc'],
    ['  X2000   TLS  OB ', 'X2000 TLS OB', 'safety-jogger-x2000-tls-ob'],
    ['Mickey Low', 'Mickey Low', 'safety-jogger-mickey-low'],
]);

it('converts EU 50 and the 49/50 jump size', function (): void {
    VendorSync::fakeHttp([['id' => 1]], [1 => sjWithSizes(1, 'BIGGER', ['49', '50', '49/50', '43/44'])]);

    $report = VendorSync::import('SJ');

    expect($report->tallies)->not->toHaveKey('unknown_size')
        ->and(ukSizeOfSku('SJ-100'))->toBe('13.5')
        ->and(ukSizeOfSku('SJ-101'))->toBe('14')
        ->and(ukSizeOfSku('SJ-102'))->toBe('13.5/14')
        ->and(ukSizeOfSku('SJ-103'))->toBe('9/10');
});

it('skips a SKU with an unknown EU size without drafting the product', function (): void {
    VendorSync::fakeHttp([['id' => 1]], [1 => sjWithSizes(1, 'MOSTLYFINE', ['42', '51', '43'])]);

    $report = VendorSync::import('SJ');
    VendorSync::syncImages(['1']);

    $product = VendorSync::product('SJ', '1');

    expect($report->created)->toBe(1)
        ->and($report->drafted)->toBe(0)
        ->and($product->status)->toBe('published')
        ->and($product->variants->pluck('sku')->sort()->values()->all())->toBe(['SJ-100', 'SJ-102'])
        ->and($report->tallies['unknown_size'])->toBe(['51' => 1])
        ->and(implode("\n", $report->messages))->toContain("SKU 101 has EU size '51', which is not in the size chart; variant not imported.");
});

it('does not create a new product whose sizes are all unknown, and drafts an existing one', function (): void {
    VendorSync::fakeHttp([['id' => 1]], [1 => sjWithSizes(1, 'NOSIZES', ['51', '52'])]);

    $report = VendorSync::import('SJ');

    expect($report->created)->toBe(0)
        ->and($report->errors)->toBe(1)
        ->and(Product::query()->count())->toBe(0)
        ->and(implode("\n", $report->messages))->toContain('no variant has a usable size; product not created');

    // The same product, first with good sizes, then with none left.
    VendorSync::fakeHttp([['id' => 1]], [1 => sjWithSizes(1, 'NOSIZES', ['42', '43'])]);
    VendorSync::import('SJ');
    VendorSync::syncImages(['1']);

    expect(VendorSync::product('SJ', '1')->status)->toBe('published');

    VendorSync::fakeHttp([['id' => 1]], [1 => sjWithSizes(1, 'NOSIZES', ['51', '52'])]);
    $report = VendorSync::import('SJ');

    $product = VendorSync::product('SJ', '1');

    expect($report->updated)->toBe(1)
        ->and($report->drafted)->toBe(1)
        ->and($product->status)->toBe('draft')
        ->and(VendorProduct::query()->sole()->draft_reasons)->toBe([DraftReason::UNKNOWN_SIZE])
        // Never deleted: the variants stay, off sale.
        ->and($product->variants)->toHaveCount(2)
        ->and($product->variants->sum('stock'))->toBe(0);
});

it('maps the models seen in the live catalogue', function (string $model, string $collection, ?string $cut, string $taxClass): void {
    VendorSync::fakeHttp([['id' => 1]], [1 => sjWithSizes(1, 'MODEL', ['42'], $model)]);

    $report = VendorSync::import('SJ');
    $product = VendorSync::product('SJ', '1');
    $variant = $product->variants->first();

    expect($product->collections->pluck('id')->all())->toBe([CatalogueKeys::collection($collection)->id])
        ->and($product->attr('cut'))->toBe($cut)
        ->and(CatalogueKeys::handleFor(KeyType::TaxClass, $variant->taxClass))->toBe($taxClass)
        ->and($report->drafted)->toBe(0);
})->with([
    ['low', 'safety-shoes', 'Low', 'standard'],
    ['sandal', 'safety-shoes', 'Low', 'standard'],
    ['clog', 'safety-shoes', 'Low', 'standard'],
    // A boot by category, but standard-rated until the accountant confirms.
    ['low_boot', 'safety-boots', 'Mid', 'standard'],
    ['high', 'safety-boots', 'High', 'zero-protective'],
    ['high_boot', 'safety-boots', 'High', 'zero-protective'],
]);

it('maps occupational pumps and sets their cut', function (): void {
    $detail = VendorSync::detail(1, 'PUMPY', 'Oxypas', 'Shoes', ['EN ISO 20347:2022'], model: 'pump');
    VendorSync::fakeHttp([['id' => 1]], [1 => $detail]);

    VendorSync::import('SJ');
    $product = VendorSync::product('SJ', '1');

    expect($product->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('occupational-footwear')->id])
        ->and($product->attr('cut'))->toBe('Low');
});

it('keeps the LG and SC feature codes and labels them', function (): void {
    $detail = VendorSync::detail(1, 'GRIPPY', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022']);
    $detail['features'] = [['name' => '1', 'value' => 'SR'], ['name' => '2', 'value' => 'LG'], ['name' => '3', 'value' => 'SC'], ['name' => '4', 'value' => 'XYZ']];
    VendorSync::fakeHttp([['id' => 1]], [1 => $detail]);

    $report = VendorSync::import('SJ');

    expect(VendorSync::product('SJ', '1')->attr('features'))->toBe(['SR', 'LG', 'SC'])
        ->and($report->tallies['unknown_feature'])->toBe(['XYZ' => 1])
        ->and(FeatureLabels::label('LG'))->toBe('Ladder grip (LG)')
        ->and(FeatureLabels::label('SC'))->toBe('Scuff cap (SC)');
});

it('keeps the 2022 safety classes as they are', function (string $supplied, string $expected): void {
    $detail = VendorSync::detail(1, 'CLASSY', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022']);
    $detail['standards'] = [['name' => $supplied, 'value' => $supplied]];
    VendorSync::fakeHttp([['id' => 1]], [1 => $detail]);

    VendorSync::import('SJ');

    expect(VendorSync::product('SJ', '1')->attr('safety_class'))->toBe($expected);
})->with([
    ['S1PS', 'S1PS'],
    ['S3S', 'S3S'],
    ['S7S', 'S7S'],
    ['O6', 'O6'],
    ['S1 PS', 'S1PS'],
]);

it('records API request counts and timings for the run and prints them', function (): void {
    VendorSync::fakeHttp(
        [['id' => 1], ['id' => 2]],
        [1 => sjWithSizes(1, 'ONE', ['42']), 2 => sjWithSizes(2, 'TWO', ['42'])],
    );

    $report = VendorSync::import('SJ');
    $stored = SyncRun::query()->where('type', 'catalogue')->sole()->stats;

    // Login, the product list and two details.
    expect($report->stats['api_requests'])->toBe(4)
        ->and($report->stats)->toHaveKeys(['api_response_seconds', 'api_average_response_ms', 'throttle_wait_seconds', 'processing_seconds', 'elapsed_seconds', 'peak_memory_mb'])
        // Sleeps are faked here, so only the real elapsed time is meaningful.
        ->and($report->stats['elapsed_seconds'])->toBeGreaterThan(0.0)
        ->and($report->stats['throttle_wait_seconds'])->toBeGreaterThan(0.0)
        ->and($stored['api_requests'])->toBe(4);

    $this->artisan('vendorsync:catalogue', ['vendor' => 'SJ', '--dry-run' => true])
        ->expectsOutputToContain('API: 3 requests')
        ->expectsOutputToContain('Peak memory')
        ->assertSuccessful();
});

it('skips a Footsure row with no usable UK size without drafting the product', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));
    VendorSync::fakeHttp();

    VendorSync::footsureFile(fn (array $rows): array => array_map(function (array $row): array {
        if ($row['Product Reference'] === '00345-00772-03') {
            $row['UK Size'] = 'One Size';
        }

        return $row;
    }, array_values(array_filter($rows, fn (array $row): bool => $row['ProductID'] === '345'))));

    $report = VendorSync::import('FS');

    expect($report->created)->toBe(1)
        ->and($report->drafted)->toBe(0)
        ->and(VendorProduct::query()->sole()->draft_reasons)->toBe([DraftReason::NO_IMAGE])
        ->and(ProductVariant::query()->pluck('sku')->sort()->values()->all())->toBe(['FS-00345-00772-04', 'FS-00345-00772-05'])
        ->and($report->tallies['unknown_size'])->toBe(['One Size' => 1]);
});
