<?php

declare(strict_types=1);

use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\VendorSync;

/*
 * The catalogue import must use the same memory for 500 products as for 50.
 *
 * 500 product details are generated from the Ligero fixture with varied
 * IDs. Each keeps the full weight of a real response (about 230 tariff
 * codes, 75 documents, the image lists); only the SKU list is shortened, to
 * keep the database work of the test short.
 */

beforeEach(function (): void {
    VendorSync::boot();
});

/**
 * Fake the API for `$count` generated products. Details are built one at a
 * time, on request, so the fake itself holds one template only. Returns a
 * function giving the memory in use when product 51 was requested, that is
 * after product 50 was fully processed.
 */
function fakeGeneratedCatalogue(int $count): Closure
{
    $template = VendorSync::json('SafetyJogger/product-284096.json');

    foreach ($template['colors'] as $index => $colour) {
        $template['colors'][$index]['skus'] = array_slice($colour['skus'], 0, 2);
    }

    $after50 = null;

    Http::fake(function (Request $request) use ($template, $count, &$after50) {
        $path = substr($request->url(), strlen(VendorSync::SJ_BASE) + 1);

        if ($path === 'login') {
            return Http::response(['hash' => 'test-hash', 'expirationDate' => now()->addDays(30)->toIso8601String()]);
        }

        if ($path === 'product') {
            return Http::response(array_map(
                fn (int $number): array => ['id' => 100000 + $number, 'brandDescription' => 'Safety Jogger', 'properties' => range(1, 150)],
                range(1, $count),
            ));
        }

        if (preg_match('#^product/(\d+)$#', $path, $match) !== 1) {
            return Http::response('unexpected request', 500);
        }

        $id = (int) $match[1];
        $number = $id - 100000;

        if ($number === 51) {
            gc_collect_cycles();
            $after50 = memory_get_usage();
        }

        $detail = $template;
        $detail['id'] = $id;
        $detail['name'] = $detail['commercialName'] = 'LIGERO'.$number;

        foreach ($detail['colors'] as $colourIndex => $colour) {
            foreach ($colour['skus'] as $skuIndex => $sku) {
                $skuId = $id * 100 + $colourIndex * 10 + $skuIndex;
                $detail['colors'][$colourIndex]['skus'][$skuIndex]['id'] = $skuId;
                $detail['colors'][$colourIndex]['skus'][$skuIndex]['eanCode'] = (string) (9000000000000 + $skuId);
            }
        }

        return Http::response(json_encode($detail), 200, ['Content-Type' => 'application/json']);
    });

    // A faked client records every request and response for assertions,
    // which the real client never does. Turn that off, or the test would
    // measure the recorder instead of the importer.
    (function (): void {
        $this->recording = false;
        $this->recorded = [];
    })->call(Http::getFacadeRoot());

    return function () use (&$after50): ?int {
        return $after50;
    };
}

it('keeps memory flat while importing 500 products', function (bool $dryRun): void {
    $megabyte = 1048576;
    $after50 = fakeGeneratedCatalogue(500);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);

    $report = VendorSync::import('SJ', dryRun: $dryRun);

    gc_collect_cycles();
    $after500 = memory_get_usage();
    $peak = memory_get_peak_usage(true);

    expect($report->status)->toBe('succeeded')
        ->and($report->seen)->toBe(500)
        ->and($report->created)->toBe(500)
        ->and($report->errors)->toBe(0)
        ->and(count($report->samples))->toBe(20)
        ->and(VendorProduct::query()->count())->toBe($dryRun ? 0 : 500);

    // Memory after product 500 is within 10 MB of memory after product 50.
    expect($after50())->not->toBeNull()
        ->and(abs($after500 - $after50()) / $megabyte)->toBeLessThan(10.0);

    // Peak memory. The 96 MB ceiling is for the artisan process, which
    // starts at about 64 MB. A test process already holds about 90 MB
    // before the import starts (the framework, the admin panel and the
    // seeded suite), so the absolute figure cannot be asserted here.
    // What the import adds on top of its starting point is asserted
    // instead, and more tightly: 64 MB + 16 MB is under 96 MB.
    expect(($peak - $baseline) / $megabyte)->toBeLessThan(16.0);

    if ($baseline < 64 * $megabyte) {
        expect($peak / $megabyte)->toBeLessThan(96.0);
    }
})->with(['real import' => false, 'dry run' => true]);

it('stores a trimmed payload: UK tariff code only, English documents only, spin frames kept', function (): void {
    $ligero = VendorSync::json('SafetyJogger/product-284096.json');
    VendorSync::fakeHttp([['id' => 284096]], [284096 => $ligero]);

    VendorSync::import('SJ');

    $stored = VendorRawRecord::query()->sole()->payload;
    $documents = collect($stored['documents']);

    expect(count($ligero['taricCodes']))->toBeGreaterThan(200)
        ->and($stored['taricCodes'])->toBe([['country' => ['iso2' => 'GB'], 'taricCode' => '6404199000']])
        ->and($documents->where('type', 'CERTIFICATE'))->toHaveCount(5)
        ->and($documents->where('type', 'DeclarationofConformity')->pluck('label')->all())->toBe(['English'])
        ->and($documents->where('type', 'PRODUCT_SHEET')->pluck('label')->unique()->all())->toBe(['English'])
        ->and($documents->where('type', 'PRODUCT_SHEET'))->toHaveCount(3)
        ->and($stored['colors'][0]['images']['360'])->toHaveCount(24)
        // The commodity code still comes through.
        ->and(VendorProduct::query()->sole()->commodity_code)->toBe('6404199000')
        ->and(strlen(json_encode($stored)))->toBeLessThan((int) (strlen(json_encode($ligero)) * 0.6));
});

it('does not treat a change in trimmed-away or stock data as a product change', function (): void {
    $ligero = VendorSync::json('SafetyJogger/product-284096.json');
    VendorSync::fakeHttp([['id' => 284096]], [284096 => $ligero]);
    VendorSync::import('SJ');

    $changed = $ligero;
    $changed['priority'] = 999;
    $changed['colors'][0]['skus'][0]['stock'] = 1;
    $changed['colors'][0]['images']['360'] = [];
    $changed['taricCodes'] = array_values(array_filter($changed['taricCodes'], fn (array $entry): bool => $entry['country']['iso2'] === 'GB'));
    $changed['documents'] = array_values(array_filter($changed['documents'], fn (array $document): bool => $document['type'] === 'CERTIFICATE' || $document['label'] === 'English'));

    VendorSync::fakeHttp([['id' => 284096]], [284096 => $changed]);

    expect(VendorSync::import('SJ')->unchanged)->toBe(1);

    $changed['productTitles'][0]['value'] = 'A new title';
    VendorSync::fakeHttp([['id' => 284096]], [284096 => $changed]);

    expect(VendorSync::import('SJ')->updated)->toBe(1);
});
