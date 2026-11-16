<?php

declare(strict_types=1);

use App\Domain\VendorSync\Exceptions\ImageSyncFailed;
use App\Domain\VendorSync\Images\ImageRun;
use App\Domain\VendorSync\Images\ProductImageSync;
use App\Domain\VendorSync\Images\SyncProductImages;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Import\RunRecorder;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use App\Domain\VendorSync\Notifications\SyncRunAlert;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Lunar\Models\Product;
use Tests\Support\VendorSync;

/*
 * The image job must never finish silently without work.
 *
 * The bug this file reproduces: `vendorsync:images SJ --product=215754`
 * queued a job that reported DONE and saved nothing. BESTBOOT's main image
 * is a 14 MB, 3692 x 3291 PNG. Media Library refused it as too big and the
 * job swallowed the exception.
 */

beforeEach(function (): void {
    VendorSync::boot();
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Freeze the clock and make sleeping advance it, so a host that hangs for 60
 * seconds per attempt costs no real time. Call after the import.
 */
function freezeClock(): void
{
    Carbon::setTestNow('2026-11-16 12:00:00');
    Sleep::fake(syncWithCarbon: true);
}

/**
 * Import BESTBOOT [215754] from its recorded detail. Its catalogue run
 * leaves the product draft, waiting for its first image.
 */
function importBestboot(array $brokenImages = [], array $hugeImages = []): VendorProduct
{
    $detail = VendorSync::json('SafetyJogger/product-215754.json');
    VendorSync::fakeHttp([['id' => 215754]], [215754 => $detail], brokenImages: $brokenImages, hugeImages: $hugeImages);
    VendorSync::import('SJ');

    return VendorProduct::query()->where('vendor_product_id', '215754')->sole();
}

function imageRun(): SyncRun
{
    return SyncRun::query()->where('type', 'images')->latest('id')->firstOrFail();
}

it('saves BESTBOOT\'s images when its vendor product ID is passed to --product', function (): void {
    // As on the live host: the main image and the first detail are larger
    // than the worker can convert.
    $link = importBestboot(hugeImages: ['BESTBOOT-BLK-HIRES.PNG', 'BESTBOOT-BLK-HR01.PNG']);

    expect(VendorSync::product('SJ', '215754')->status)->toBe('draft')
        ->and($link->draft_reasons)->toBe([DraftReason::NO_IMAGE]);

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])
        ->expectsOutputToContain('Queued 1 image job for SJ product 215754.')
        ->assertSuccessful();

    $product = VendorSync::product('SJ', '215754');
    $media = $product->getMedia('images');
    $link->refresh();

    // HIRES, HR01 to HR04 and the outsole: six images, all saved.
    expect($media)->toHaveCount(6)
        ->and($media->map(fn ($image): string => $image->getCustomProperty('role'))->all())->toBe(['main', 'detail', 'detail', 'detail', 'detail', 'outsole'])
        // The two oversized originals were replaced by the 1280 pixel renditions.
        ->and($media[0]->getCustomProperty('source_url'))->toEndWith('BESTBOOT-BLK-HIRES.PNG')
        ->and($media[0]->getCustomProperty('downloaded_url'))->toEndWith('BESTBOOT-BLK-WEB01.JPG')
        ->and($media[1]->getCustomProperty('downloaded_url'))->toEndWith('BESTBOOT-BLK-WEB02.JPG')
        ->and($media[2]->getCustomProperty('downloaded_url'))->toEndWith('BESTBOOT-BLK-HR02.PNG')
        ->and($media[0]->getCustomProperty('primary'))->toBeTrue()
        // no_image is gone, the product is published, and the sync is recorded.
        ->and($link->draft_reasons)->toBe([])
        ->and($product->status)->toBe('published')
        ->and($link->images_synced_at)->not->toBeNull()
        ->and($link->image_urls_hash)->toHaveLength(64)
        ->and($product->variants->first()->images)->toHaveCount(6)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $run = imageRun();

    expect($run->status)->toBe('succeeded')
        ->and($run->seen)->toBe(1)
        ->and($run->updated)->toBe(1)
        ->and($run->errors)->toBe(0)
        ->and(implode("\n", $run->messages))->toContain('BESTBOOT-BLK-HIRES.PNG: the image is 3692 x 3291 (12.2 megapixels, limit 5.0); used BESTBOOT-BLK-WEB01.JPG instead');

    // The image host is public: no API credentials are sent to it.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/image/'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/image/')
        && ($request->hasHeader('secret') || $request->hasHeader('username')));
});

it('reindexes the product once it has an image', function (): void {
    $link = importBestboot();
    $indexed = [];

    Product::saved(function (Product $product) use (&$indexed): void {
        $indexed[] = $product->status;
    });

    $result = app(ProductImageSync::class)->sync($link);

    // The status change is saved with search syncing on, which is what
    // sends the product to the index.
    expect($result->changed)->toBeTrue()
        ->and($result->stored)->toBe(6)
        ->and($indexed)->toContain('published');
});

it('rejects an unknown --product before queuing anything', function (string $product): void {
    importBestboot();

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => $product])
        ->expectsOutputToContain("SJ has no imported product with vendor product ID '{$product}'. Nothing was queued.")
        ->assertFailed();

    expect(SyncRun::query()->where('type', 'images')->count())->toBe(0);
})->with([
    // The Lunar product ID is not the vendor product ID.
    'a Lunar product ID' => fn (): string => (string) VendorProduct::query()->value('product_id') === '215754' ? '1' : (string) (VendorProduct::query()->value('product_id') ?? '18'),
    'an unknown ID' => '999999',
]);

it('fails loudly when the vendor product no longer exists', function (): void {
    Log::spy();
    $run = app(RunRecorder::class)->start(app(RunRecorder::class)->vendor('SJ'), ImageRun::TYPE);

    expect(fn () => (new SyncProductImages(987654, false, $run->id))->handle(app(ProductImageSync::class)))
        ->toThrow(ImageSyncFailed::class, 'the vendor product no longer exists');

    expect($run->refresh()->errors)->toBe(1)
        ->and($run->messages[0])->toContain('Image sync failed for vendor product row 987654');

    Log::shouldHaveReceived('error')->once();
});

it('fails loudly when the image plan is empty', function (string $case, string $reason): void {
    Log::spy();
    $link = importBestboot();

    if ($case === 'no payload') {
        VendorRawRecord::query()->delete();
    } else {
        $raw = VendorRawRecord::query()->sole();
        $payload = $raw->payload;
        $payload['colors'][0]['images'] = ['360' => $payload['colors'][0]['images']['360']];
        $raw->update(['payload' => $payload]);
    }

    $run = app(RunRecorder::class)->start($link->vendor, ImageRun::TYPE);

    expect(fn () => (new SyncProductImages($link->id, false, $run->id))->handle(app(ProductImageSync::class)))
        ->toThrow(ImageSyncFailed::class, "Image sync failed for vendor product 215754: {$reason}");

    $link->refresh();

    expect($run->refresh()->errors)->toBe(1)
        ->and($run->messages[0])->toContain('215754')
        ->and($run->messages[0])->toContain($reason)
        ->and($link->images_synced_at)->toBeNull()
        ->and($link->draft_reasons)->toBe([DraftReason::NO_IMAGE])
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('draft');

    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, '215754') && str_contains($message, $reason))->once();
})->with([
    ['no payload', 'no stored vendor payload; run the catalogue import first'],
    ['no images', 'the vendor payload lists no images for this product'],
]);

it('fails loudly when every download fails, and the job lands in failed_jobs', function (): void {
    Log::spy();
    $link = importBestboot(brokenImages: ['BESTBOOT']);

    // Through a real queue and worker, as locally and in production.
    config()->set('queue.default', 'database');

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

    $run = imageRun();
    $link->refresh();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('Image sync failed for vendor product 215754: none of the 6 images could be saved')
        ->and($run->status)->toBe('failed')
        ->and($run->errors)->toBe(1)
        ->and($run->messages[0])->toContain('none of the 6 images could be saved')
        ->and($run->messages[0])->toContain('BESTBOOT-BLK-HIRES.PNG: download failed')
        ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(0)
        ->and($link->images_synced_at)->toBeNull()
        ->and($link->image_urls_hash)->toBeNull()
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('draft');

    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'vendor product 215754'))->atLeast()->once();
    Notification::assertSentOnDemand(SyncRunAlert::class);
});

it('fails loudly when an oversized image has no smaller rendition and nothing else can be saved', function (): void {
    $link = importBestboot(brokenImages: ['HR0', 'OUTS', 'WEB'], hugeImages: ['BESTBOOT-BLK-HIRES.PNG']);

    expect(fn () => (new SyncProductImages($link->id))->handle(app(ProductImageSync::class)))
        ->toThrow(ImageSyncFailed::class, 'the image is 3692 x 3291 (12.2 megapixels, limit 5.0), and the smaller rendition');
});

it('treats a partial success as a success with a warning', function (): void {
    Log::spy();
    $link = importBestboot(brokenImages: ['BESTBOOT-BLK-HR03', 'BESTBOOT-BLK-OUTS']);

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

    $run = imageRun();
    $link->refresh();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and($run->status)->toBe('succeeded')
        ->and($run->updated)->toBe(1)
        ->and($run->errors)->toBe(2)
        ->and(implode("\n", $run->messages))->toContain('Product 215754: 2 of 6 images could not be saved')
        ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(4)
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('published')
        ->and($link->draft_reasons)->toBe([])
        ->and($link->images_synced_at)->not->toBeNull()
        ->and($link->image_urls_hash)->toHaveLength(64);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Product 215754: 2 of 6 images'))->once();
    Log::shouldNotHaveReceived('error');
});

it('does not publish a product that has images but another draft reason', function (): void {
    $link = importBestboot();
    $link->update(['draft_reasons' => [DraftReason::UNMAPPED_CATEGORY, DraftReason::NO_IMAGE]]);

    app(ProductImageSync::class)->sync($link);

    expect($link->refresh()->draft_reasons)->toBe([DraftReason::UNMAPPED_CATEGORY])
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('draft')
        ->and($link->images_synced_at)->not->toBeNull();
});

it('does nothing, and does not fail, when the images are already up to date', function (): void {
    $link = importBestboot();
    app(ProductImageSync::class)->sync($link);

    Http::fake();
    $requestsBefore = count(Http::recorded());

    (new SyncProductImages($link->id))->handle(app(ProductImageSync::class));

    expect(count(Http::recorded()))->toBe($requestsBefore)
        ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(6);
});

it('records a failed job and carries on with the next product on the sync queue', function (): void {
    Log::spy();
    $bestboot = VendorSync::json('SafetyJogger/product-215754.json');
    $tactic = VendorSync::json('SafetyJogger/product-279074.json');
    VendorSync::fakeHttp([['id' => 215754], ['id' => 279074]], [215754 => $bestboot, 279074 => $tactic], brokenImages: ['BESTBOOT']);
    VendorSync::import('SJ');

    $this->artisan('vendorsync:images', ['vendor' => 'SJ'])
        ->expectsOutputToContain('Queued 2 image jobs for SJ.')
        ->assertSuccessful();

    $run = imageRun();

    // BESTBOOT failed and was recorded; TACTIC after it was still done.
    expect($run->status)->toBe('succeeded')
        ->and($run->seen)->toBe(2)
        ->and($run->errors)->toBe(1)
        ->and($run->stats['failed_jobs'])->toBe(1)
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('draft')
        ->and(VendorSync::product('SJ', '279074')->status)->toBe('published');

    Log::shouldHaveReceived('error')->once();
});

it('stops starting downloads once the time budget cannot cover another one, and reports each as not attempted', function (): void {
    $link = importBestboot();
    freezeClock();
    // Every attempt hangs for the full 60 s: 3 x 60 + 2 x 0.5 = 181 s per image.
    VendorSync::hangImages(['BESTBOOT'], 60);

    $result = app(ProductImageSync::class)->sync($link, false, now()->addSeconds(400));

    // Images 1 and 2 are tried (400 s and 219 s left, 197 s needed); 38 s are left for image 3.
    expect(VendorSync::hangingRequests())->toBe(6)
        ->and($result->planned)->toBe(6)
        ->and($result->failed)->toHaveCount(6)
        ->and($result->notAttempted)->toBe(4)
        ->and($result->stored)->toBe(0)
        ->and($result->failed[0])->toContain('connection failed or timed out')
        ->and($result->failed[1])->toContain('(after 3 attempts)')
        ->and($result->failed[2])->toContain('not attempted: job time budget exhausted (38s left, a download can take up to 197s)')
        ->and($result->failed[5])->toContain('not attempted');
});

it('reports a job whose host hangs, instead of letting the queue kill it at its timeout', function (): void {
    $link = importBestboot();
    freezeClock();
    VendorSync::hangImages(['BESTBOOT'], 60);

    $run = app(RunRecorder::class)->start($link->vendor, ImageRun::TYPE);
    $job = new SyncProductImages($link->id, false, $run->id);
    // 450 s timeout minus the 60 s margin leaves a 390 s budget.
    $job->timeout = 450;
    $startedAt = now();

    expect(fn () => $job->handle(app(ProductImageSync::class)))
        ->toThrow(ImageSyncFailed::class, 'not attempted: job time budget exhausted');

    // Two images were tried (362 s), the other four were not, and the job
    // finished well inside its 450 s with its failure recorded in the run.
    expect(VendorSync::hangingRequests())->toBe(6)
        ->and($startedAt->diffInSeconds(now(), true))->toBeLessThan(450)
        ->and($run->refresh()->errors)->toBe(1)
        ->and($run->messages[0])->toContain('none of the 6 images could be saved')
        ->and($run->messages[0])->toContain('not attempted: job time budget exhausted');
});

it('does not download the smaller rendition when the original used up the time budget', function (): void {
    $link = importBestboot(hugeImages: ['BESTBOOT-BLK-HIRES.PNG']);
    freezeClock();
    // The oversized original takes 100 s to arrive.
    VendorSync::slowImages(['BESTBOOT-BLK-HIRES.PNG'], 100);

    $result = app(ProductImageSync::class)->sync($link, false, now()->addSeconds(250));

    // 250 s covers the original (197 s needed), the 150 s left after it do not cover the rendition.
    expect($result->failed[0])->toContain('BESTBOOT-BLK-HIRES.PNG: the image is 3692 x 3291')
        ->and($result->failed[0])->toContain('and the smaller rendition')
        ->and($result->failed[0])->toContain('was not attempted: job time budget exhausted (150s left, a download can take up to 197s)')
        ->and($result->notAttempted)->toBe(6);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'BESTBOOT-BLK-HIRES.PNG'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'BESTBOOT-BLK-WEB01.JPG'));
});
