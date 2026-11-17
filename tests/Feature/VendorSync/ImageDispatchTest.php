<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\ImageRun;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Lunar\Models\Product;
use Tests\Support\VendorSync;

/*
 * A plain image run queues a job only for a product whose images are incomplete
 * or changed. Products that are up to date are counted, not queued.
 */

beforeEach(function (): void {
    VendorSync::boot();
    VendorSync::fakeHttp();
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));

    VendorSync::footsureFile();
    VendorSync::import('FS');

    // One product with its images saved and its hash written; the rest have nothing.
    // (Each sync converts every image, so one is all the tests can afford.)
    $this->synced = VendorProduct::query()->orderBy('id')->limit(1)->pluck('vendor_product_id')->all();
    VendorSync::syncImages($this->synced);
    $this->total = VendorProduct::query()->count();

    config()->set('queue.default', 'database');
    DB::table('jobs')->delete();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function syncedLink(string $id): VendorProduct
{
    return VendorProduct::query()->where('vendor_product_id', $id)->sole();
}

function lastImageRun(): SyncRun
{
    return SyncRun::query()->where('type', ImageRun::TYPE)->latest('id')->firstOrFail();
}

it('starts from one product with images and a stored hash', function (): void {
    expect($this->total)->toBeGreaterThan(3)
        ->and(syncedLink($this->synced[0])->image_urls_hash)->toHaveLength(64)
        ->and(Product::query()->findOrFail(syncedLink($this->synced[0])->product_id)->getMedia('images'))->not->toBeEmpty();
});

it('queues only the products whose images are not up to date, and counts the rest', function (): void {
    $this->artisan('vendorsync:images', ['vendor' => 'FS'])
        ->expectsOutputToContain('Queued '.($this->total - 1).' image jobs for FS.')
        ->expectsOutputToContain('1 products with up-to-date images were not queued')
        ->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe($this->total - 1)
        ->and(lastImageRun()->stats['skipped_up_to_date'])->toBe(1);
});

it('queues nothing, and finishes the run, when every product is up to date', function (): void {
    VendorProduct::query()->where('vendor_product_id', '!=', $this->synced[0])->delete();

    $this->artisan('vendorsync:images', ['vendor' => 'FS'])
        ->expectsOutputToContain('Queued 0 image jobs for FS.')
        ->assertSuccessful();

    $run = lastImageRun();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($run->status)->toBe('succeeded')
        ->and($run->stats['skipped_up_to_date'])->toBe(1);
});

it('queues a product again when its image state is incomplete or changed', function (Closure $change): void {
    $change(syncedLink($this->synced[0]));

    $this->artisan('vendorsync:images', ['vendor' => 'FS'])->assertSuccessful();

    // The one synced product is no longer up to date: every product is queued.
    expect(DB::table('jobs')->count())->toBe($this->total)
        ->and(lastImageRun()->stats['skipped_up_to_date'] ?? 0)->toBe(0);
})->with([
    'a cleared hash (write-ahead before indexing)' => [fn (VendorProduct $link) => $link->update(['image_urls_hash' => null])],
    'an incomplete hash (some images failed)' => [fn (VendorProduct $link) => $link->update(['image_urls_hash' => hash('sha256', 'previous|incomplete|a.jpg: HTTP 404')])],
    'a changed image list (another hash)' => [fn (VendorProduct $link) => $link->update(['image_urls_hash' => hash('sha256', 'an older image list')])],
    'deleted media' => [fn (VendorProduct $link) => Product::query()->findOrFail($link->product_id)->clearMediaCollection('images')],
    'a blocked plan (no stored payload)' => [fn (VendorProduct $link) => VendorRawRecord::query()->where('vendor_id', $link->vendor_id)->where('external_id', $link->vendor_product_id)->delete()],
]);

it('queues everything with --refresh, and the named product with --product, whatever its state', function (): void {
    $this->artisan('vendorsync:images', ['vendor' => 'FS', '--refresh' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe($this->total)
        ->and(lastImageRun()->stats['skipped_up_to_date'] ?? 0)->toBe(0);

    DB::table('jobs')->delete();

    $this->artisan('vendorsync:images', ['vendor' => 'FS', '--product' => $this->synced[0]])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(1);
});

it('asks which products have images once per chunk, not once per product', function (): void {
    DB::enableQueryLog();

    app(ImageRun::class)->run('FS');

    $mediaQueries = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_contains($entry['query'], 'from `media`'));

    // The fixture has fewer than 200 products: one chunk, one media query.
    expect($this->total)->toBeLessThan(200)
        ->and($mediaQueries)->toHaveCount(1);
});
