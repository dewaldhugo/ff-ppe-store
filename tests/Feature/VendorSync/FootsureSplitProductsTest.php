<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\VendorSync\Footsure\FootsureSource;
use App\Domain\VendorSync\Images\ImageRun;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Lunar\Models\Url;
use Tests\Support\VendorSync;

/*
 * Footsure lists one product line under several ProductIDs, one per size band.
 * The fixture has two such lines, both from the real feed:
 *
 *   Hi-Tec Squash trainer: 546 (sizes 3 to 6.5) and 547 (sizes 8 to 13), colour White
 *   6045/PSG27 Gusset Plimsolls: 569, 570 and 571 (Unisex; 568 is the Men's version)
 *
 * They are imported as one product each, with a vendor_products row per ProductID.
 */

beforeEach(function (): void {
    VendorSync::boot();
    VendorSync::fakeHttp();
    Notification::fake();

    // The fixture export was created on 11/11/2026 at 06:05.
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Imports with the pre-merge behaviour: every ProductID its own product (how the database looked before). */
function importWithoutMerging(): void
{
    app()->bind('vendorsync.source.FS', fn (): FootsureSource => new FootsureSource(config('vendorsync.footsure')));
    VendorSync::import('FS');
    app()->bind('vendorsync.source.FS', fn ($app): FootsureSource => new FootsureSource(
        config('vendorsync.footsure'),
        mapper: $app->make('vendorsync.mapper.FS'),
    ));
}

/**
 * The rows of the fixture for these ProductIDs, with a change applied.
 *
 * @param  list<string>  $ids
 */
function fixtureRows(array $ids): array
{
    $lines = file(VendorSync::fixture('Footsure/footsure.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $columns = str_getcsv((string) array_shift($lines), ',', '"', '');

    return array_values(array_filter(
        array_map(fn (string $line): array => array_combine($columns, str_getcsv($line, ',', '"', '')), $lines),
        fn (array $row): bool => in_array($row['ProductID'], $ids, true),
    ));
}

it('imports a line the vendor split by size band as one product with a link per ProductID', function (): void {
    VendorSync::footsureFile();

    $report = VendorSync::import('FS');

    // 32 ProductIDs in the file; 546 and 547 are one line, and 569, 570 and 571 are another.
    expect($report->status)->toBe('succeeded')
        ->and($report->seen)->toBe(29)
        ->and($report->created)->toBe(29)
        ->and($report->errors)->toBe(0)
        ->and(VendorProduct::query()->count())->toBe(32)
        ->and(Product::query()->count())->toBe(29);

    $squash = VendorSync::product('FS', '546');
    $skus = $squash->variants->pluck('sku')->all();

    expect(VendorSync::product('FS', '547')->is($squash))->toBeTrue()
        // Variants of both ProductIDs, with the vendor's own SKUs.
        ->and($skus)->toContain('FS-00546-01285-01')->toContain('FS-00547-01260-15')
        ->and(VendorOffer::query()->whereIn('product_variant_id', $squash->variants->pluck('id'))->count())->toBe(count($skus))
        // One slug, never `-2`.
        ->and($squash->defaultUrl->slug)->toBe('hi-tec-squash-trainer')
        ->and(Url::query()->where('slug', 'like', 'hi-tec-squash-trainer%')->count())->toBe(1)
        ->and(VendorProduct::query()->where('product_id', $squash->id)->count())->toBe(2);

    // The size options are one scale, and each colour and size is sold once.
    $pairs = $squash->variants->map(fn (ProductVariant $variant): string => $variant->values->map->translate('name')->sort()->implode('|'));

    expect($pairs->unique()->count())->toBe($pairs->count());

    // Gusset plimsolls: three ProductIDs, one product; the Men's 568 stays its own.
    $plimsolls = VendorSync::product('FS', '569');

    expect(VendorSync::product('FS', '570')->is($plimsolls))->toBeTrue()
        ->and(VendorSync::product('FS', '571')->is($plimsolls))->toBeTrue()
        ->and(VendorSync::product('FS', '568')->is($plimsolls))->toBeFalse();
});

it('takes the product content from the lowest ProductID', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $first = collect(fixtureRows(['546']))->first();
    $squash = VendorSync::product('FS', '546');

    expect($squash->translateAttribute('name'))->toBe($first['Short Product Name'])
        ->and($squash->translateAttribute('title'))->toBe($first['Long Product Name'])
        ->and($squash->translateAttribute('description'))->toBe($first['InspirationalCopy']);
});

it('stores the payload of the whole line under each of its ProductIDs', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $stored = VendorRawRecord::query()->whereIn('external_id', ['546', '547'])->get();

    expect($stored)->toHaveCount(2)
        ->and($stored->pluck('payload_hash')->unique())->toHaveCount(1)
        ->and($stored->first()->payload['members'])->toBe(['546', '547'])
        ->and(collect($stored->first()->payload['rows'])->pluck('ProductID')->unique()->sort()->values()->all())->toBe(['546', '547']);
});

it('does not write anything on a second run', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    VendorSync::footsureFile();
    $report = VendorSync::import('FS');

    expect($report->unchanged)->toBe(29)
        ->and($report->updated)->toBe(0)
        ->and($report->created)->toBe(0);
});

it('counts a split line once in a dry run, as the real run does', function (): void {
    VendorSync::footsureFile();

    $dry = VendorSync::import('FS', dryRun: true);

    expect($dry->seen)->toBe(29)
        ->and($dry->created)->toBe(29)
        ->and(Product::query()->count())->toBe(0);
});

it('puts a line on clearance only when every ProductID of it is', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $clearance = CatalogueKeys::collection('clearance')->id;
    $inClearance = fn (string $id): bool => VendorSync::product('FS', $id)->collections->pluck('id')->contains($clearance);

    // All three plimsoll ProductIDs are clearance; the squash trainer is live.
    expect($inClearance('569'))->toBeTrue()
        ->and($inClearance('546'))->toBeFalse();

    // One ProductID back at full price takes the whole line out of the range.
    VendorSync::footsureFile(function (array $rows): array {
        foreach ($rows as $index => $row) {
            if ($row['ProductID'] === '571') {
                $rows[$index]['Product Status'] = 'Live';
                $rows[$index]['Colour Status'] = 'Live';
            }
        }

        return $rows;
    });
    VendorSync::import('FS');

    expect($inClearance('569'))->toBeFalse();
});

it('adds a new size band of a line to its product', function (): void {
    VendorSync::footsureFile(fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => $row['ProductID'] !== '547')));
    VendorSync::import('FS');

    $squash = VendorSync::product('FS', '546');
    $before = $squash->variants()->count();

    expect(VendorProduct::query()->where('product_id', $squash->id)->count())->toBe(1);

    // The vendor adds the large sizes as another ProductID.
    VendorSync::footsureFile();
    $report = VendorSync::import('FS');

    expect($report->errors)->toBe(0)
        ->and(VendorProduct::query()->where('product_id', $squash->id)->count())->toBe(2)
        ->and(VendorSync::product('FS', '547')->is($squash))->toBeTrue()
        ->and($squash->variants()->count())->toBeGreaterThan($before)
        // 547 joined 546's product: still 29 products, now with 32 links.
        ->and(Product::query()->count())->toBe(29);
});

it('takes a band the vendor stops listing off sale without drafting the product', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $squash = VendorSync::product('FS', '546');
    $band = $squash->variants->filter(fn (ProductVariant $variant): bool => str_starts_with($variant->sku, 'FS-00547-'));

    expect($band)->not->toBeEmpty();

    // Three runs without ProductID 547: well past the two missed runs that draft a product.
    foreach (range(1, 3) as $run) {
        VendorSync::footsureFile(fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => $row['ProductID'] !== '547')), name: "run{$run}.csv");
        $report = VendorSync::import('FS');

        expect($report->errors)->toBe(0);
    }

    $links = VendorProduct::query()->where('product_id', $squash->id)->get();

    expect($links)->toHaveCount(2)
        ->and($links->pluck('draft_reasons')->flatten()->all())->not->toContain(DraftReason::MISSING_FROM_FEED)
        ->and($links->firstWhere('vendor_product_id', '547')->missed_runs)->toBe(0)
        // Its SKUs are no longer listed, so they are off sale; the rest is as it was.
        ->and(ProductVariant::query()->whereIn('id', $band->pluck('id'))->pluck('stock')->unique()->all())->toBe([0])
        ->and(VendorOffer::query()->whereIn('product_variant_id', $band->pluck('id'))->pluck('sellable')->unique()->all())->toBe([false])
        ->and($squash->variants()->where('stock', '>', 0)->count())->toBeGreaterThan(0);
});

it('still drafts a line when every ProductID of it has gone', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $squash = VendorSync::product('FS', '546');
    $squash->update(['status' => 'published']);

    foreach (range(1, 2) as $run) {
        VendorSync::footsureFile(fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => ! in_array($row['ProductID'], ['546', '547'], true))), name: "gone{$run}.csv");
        VendorSync::import('FS');
    }

    expect($squash->refresh()->status)->toBe('draft')
        ->and($squash->variants()->where('stock', '>', 0)->count())->toBe(0)
        ->and(VendorProduct::query()->where('product_id', $squash->id)->get()->every(fn (VendorProduct $link): bool => in_array(DraftReason::MISSING_FROM_FEED, $link->draft_reasons, true)))->toBeTrue();
});

it('does not write a line whose ProductIDs are still on separate products, and says how to fix it', function (): void {
    VendorSync::footsureFile();
    importWithoutMerging();

    expect(Product::query()->count())->toBe(32);

    VendorSync::footsureFile();
    $report = VendorSync::import('FS');

    expect($report->errors)->toBe(2)
        ->and(collect($report->messages)->implode("\n"))->toContain('vendorsync:merge-splits FS')
        // Nothing was merged or lost by the run.
        ->and(Product::query()->count())->toBe(32)
        ->and(VendorProduct::query()->distinct()->count('product_id'))->toBe(32);
});

it('queues one image job for a merged product, whichever of its IDs is named', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');
    config()->set('queue.default', 'database');
    DB::table('jobs')->delete();

    $products = VendorProduct::query()->distinct()->count('product_id');

    expect(VendorProduct::query()->primary()->count())->toBe($products)
        ->and(VendorProduct::query()->count())->toBe($products + 3);

    app(ImageRun::class)->run('FS');

    expect(DB::table('jobs')->count())->toBe($products);

    DB::table('jobs')->delete();
    app(ImageRun::class)->run('FS', '547');

    // Named by the second ID, queued once, for the line's primary link.
    $primary = VendorProduct::query()->where('vendor_product_id', '546')->sole();

    $job = unserialize((string) json_decode((string) DB::table('jobs')->value('payload'), true)['data']['command']);

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($job->vendorProductId)->toBe($primary->id);
});

it('stores the images of every ProductID of the line on the one product', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $squash = VendorSync::product('FS', '546');
    $primary = VendorProduct::query()->where('vendor_product_id', '546')->sole();

    VendorSync::syncImages(['546']);

    $urls = $squash->refresh()->getMedia('images')->map(fn ($media): string => (string) $media->getCustomProperty('source_url'))->all();

    // The picture of each band is on the product, and the two ProductIDs do not delete each other's.
    expect($urls)->toContain('http://ts-images.gardinerbros.co.uk/hitec/gbt-524-425-f-a-2.jpg')
        ->and($urls)->toContain('http://ts-images.gardinerbros.co.uk/hitec/gbt-525-426-f-a-2.jpg')
        ->and($primary->refresh()->image_urls_hash)->toHaveLength(64);

    // Syncing again, through the other ID, changes nothing.
    VendorSync::syncImages(['546', '547']);

    expect($squash->refresh()->getMedia('images')->count())->toBe(count($urls));
})->skip(fn (): bool => ! extension_loaded('gd'), 'needs GD');
