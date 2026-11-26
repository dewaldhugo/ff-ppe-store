<?php

declare(strict_types=1);

use App\Domain\VendorSync\Exceptions\ImageSyncFailed;
use App\Domain\VendorSync\Images\ImageDownloader;
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
use Illuminate\Support\Facades\Cache;
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
function importBestboot(array $brokenImages = [], array $hugeImages = [], array $realImages = []): VendorProduct
{
    $detail = VendorSync::json('SafetyJogger/product-215754.json');
    VendorSync::fakeHttp([['id' => 215754]], [215754 => $detail], brokenImages: $brokenImages, hugeImages: $hugeImages, realImages: $realImages);
    VendorSync::import('SJ');

    return VendorProduct::query()->where('vendor_product_id', '215754')->sole();
}

function imageRun(): SyncRun
{
    return SyncRun::query()->where('type', 'images')->latest('id')->firstOrFail();
}

it('saves BESTBOOT\'s images when its vendor product ID is passed to --product', function (): void {
    // The size limit is off by default; this test is about the optional one.
    config(['vendorsync.images.max_megapixels' => 7.0]);

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
        ->and(implode("\n", $run->messages))->toContain('BESTBOOT-BLK-HIRES.PNG: the image is 3692 x 3291 (12.2 megapixels, limit 7.0); used BESTBOOT-BLK-WEB01.JPG instead');

    // The image host is public: no API credentials are sent to it.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/image/'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/image/')
        && ($request->hasHeader('secret') || $request->hasHeader('username')));
});

it('accepts a 2560 x 2560 vendor original and converts it', function (): void {
    // The size the production run rejected at the old 5.0 megapixel limit. A real
    // decode, so the process needs the memory a worker has (256M on the server).
    // Only ever raised: a full run of the suite may already hold more than 256M.
    $memory = (string) ini_get('memory_limit');
    $bytes = (int) $memory * match (strtoupper(substr($memory, -1))) {
        'G' => 1024 ** 3,
        'M' => 1024 ** 2,
        'K' => 1024,
        default => 1,
    };

    if ($memory !== '-1' && $bytes < 256 * 1024 ** 2) {
        ini_set('memory_limit', '256M');
    }

    try {
        $link = importBestboot(realImages: ['BESTBOOT-BLK-HIRES.PNG' => [2560, 2560]]);

        // No limit by default.
        expect((float) config('vendorsync.images.max_megapixels'))->toBe(0.0);

        $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

        $media = VendorSync::product('SJ', '215754')->getMedia('images');
        $run = imageRun();

        // The original itself was used, shrunk to 1200 wide and stored as WebP: no smaller rendition was used.
        [$storedWidth, $storedHeight] = getimagesize($media[0]->getPath());

        expect($media)->toHaveCount(6)
            ->and($media[0]->mime_type)->toBe('image/webp')
            ->and([$storedWidth, $storedHeight])->toBe([1200, 1200])
            ->and($media[0]->getCustomProperty('source_url'))->toEndWith('BESTBOOT-BLK-HIRES.PNG')
            ->and($media[0]->getCustomProperty('downloaded_url'))->toEndWith('BESTBOOT-BLK-HIRES.PNG')
            ->and($media[0]->hasGeneratedConversion('small'))->toBeTrue()
            ->and($run->status)->toBe('succeeded')
            ->and($run->errors)->toBe(0)
            ->and(implode('
', $run->messages))->not->toContain('megapixels')
            ->and($link->refresh()->image_urls_hash)->toHaveLength(64)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    } finally {
        ini_set('memory_limit', $memory);
    }
});

it('still rejects an image above the limit and uses the smaller rendition', function (): void {
    // The size limit is off by default; this test is about the optional one.
    config(['vendorsync.images.max_megapixels' => 7.0]);

    // 2700 x 2700 is 7.29 megapixels: just over the 7.0 limit.
    importBestboot(hugeImages: ['BESTBOOT-BLK-HIRES.PNG' => [2700, 2700]]);

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

    $media = VendorSync::product('SJ', '215754')->getMedia('images');

    expect($media[0]->getCustomProperty('downloaded_url'))->toEndWith('BESTBOOT-BLK-WEB01.JPG')
        ->and(implode('
', imageRun()->messages))->toContain('BESTBOOT-BLK-HIRES.PNG: the image is 2700 x 2700 (7.3 megapixels, limit 7.0); used BESTBOOT-BLK-WEB01.JPG instead');
});

it('accepts an image of exactly the limit and rejects one pixel row more', function (): void {
    // A 4.0 megapixel limit keeps the real decode small. The check is "above".
    config(['vendorsync.images.max_megapixels' => 4.0]);

    importBestboot(realImages: ['BESTBOOT-BLK-HIRES.PNG' => [2000, 2000]], hugeImages: ['BESTBOOT-BLK-HR01.PNG' => [2000, 2001]]);

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

    $media = VendorSync::product('SJ', '215754')->getMedia('images');

    expect($media[0]->getCustomProperty('downloaded_url'))->toEndWith('BESTBOOT-BLK-HIRES.PNG')
        ->and(implode('
', imageRun()->messages))->toContain('BESTBOOT-BLK-HR01.PNG: the image is 2000 x 2001 (4.0 megapixels, limit 4.0); used BESTBOOT-BLK-WEB02.JPG instead');
});

it('stores every image as WebP at most 1200 wide, however large the original is', function (): void {
    // 3000 x 3000 is 9 megapixels: over the old 7.0 limit, and now simply converted.
    importBestboot(realImages: ['BESTBOOT-BLK-HIRES.PNG' => [3000, 3000]]);

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

    $media = VendorSync::product('SJ', '215754')->getMedia('images');

    expect($media)->toHaveCount(6)
        ->and($media[0]->getCustomProperty('downloaded_url'))->toEndWith('BESTBOOT-BLK-HIRES.PNG')
        ->and(implode("\n", imageRun()->messages))->not->toContain('megapixels');

    foreach ($media as $image) {
        [$width] = getimagesize($image->getPath());

        expect($image->mime_type)->toBe('image/webp')
            ->and($image->file_name)->toEndWith('.webp')
            ->and($width)->toBeLessThanOrEqual(1200)
            // A Safety Jogger main image is also put on the baseline; its other pictures are not.
            ->and($image->getCustomProperty('optimised'))->toBe($image->getCustomProperty('role') === 'main' ? 'webp-1200-q85-base15-top15-side4-ar100-grow-bg' : 'webp-1200-q85');
    }

    // The 9 megapixel original was shrunk, not kept.
    [$width, $height] = getimagesize($media[0]->getPath());

    expect([$width, $height])->toBe([1200, 1200]);
});

it('fetches an image stored before the WebP rule again and replaces it', function (): void {
    $link = importBestboot();

    app(ProductImageSync::class)->sync($link);

    $product = VendorSync::product('SJ', '215754');
    $before = $product->getMedia('images');

    // As the images of an earlier run: no `optimised` property, and a plan hash from before the signature existed.
    foreach ($before as $image) {
        $image->setCustomProperty('optimised', null)->save();
    }

    $link->refresh()->update(['image_urls_hash' => hash('sha256', 'before the webp rule')]);

    $result = app(ProductImageSync::class)->sync($link->refresh());

    $after = $product->refresh()->getMedia('images');

    expect($result->downloaded)->toBe(6)
        ->and($after)->toHaveCount(6)
        ->and($after->pluck('id')->intersect($before->pluck('id'))->all())->toBe([])
        ->and($after->every(fn ($image): bool => $image->getCustomProperty('optimised') === ($image->getCustomProperty('role') === 'main' ? 'webp-1200-q85-base15-top15-side4-ar100-grow-bg' : 'webp-1200-q85')))->toBeTrue();
});

it('fetches only the main images again when the baseline rule is added, and not when it is switched off', function (): void {
    $link = importBestboot();

    // Stored before the rule: every image in plain WebP.
    config(['vendorsync.images.align.vendors' => []]);
    app(ProductImageSync::class)->sync($link);

    $product = VendorSync::product('SJ', '215754');
    $before = $product->getMedia('images');

    expect($before->every(fn ($image): bool => $image->getCustomProperty('optimised') === 'webp-1200-q85'))->toBeTrue();

    // Switched on for Safety Jogger: the plan hash moves, and only the main images are replaced.
    config(['vendorsync.images.align.vendors' => ['SJ']]);

    $result = app(ProductImageSync::class)->sync($link->refresh());

    $after = $product->refresh()->getMedia('images');
    $mains = $before->filter(fn ($image): bool => $image->getCustomProperty('role') === 'main');

    expect($result->downloaded)->toBe($mains->count())
        ->and($after)->toHaveCount($before->count())
        // The pictures that are not main images are the same files as before.
        ->and($after->pluck('id')->intersect($before->reject(fn ($image): bool => $image->getCustomProperty('role') === 'main')->pluck('id'))->count())->toBe($before->count() - $mains->count())
        ->and($after->filter(fn ($image): bool => $image->getCustomProperty('role') === 'main')->every(fn ($image): bool => $image->getCustomProperty('optimised') === 'webp-1200-q85-base15-top15-side4-ar100-grow-bg'))->toBeTrue();
});

it('puts only the safety shoes and safety boots of Safety Jogger on the baseline', function (): void {
    $link = importBestboot();

    // BESTBOOT is a boot, so it is in the list.
    config(['vendorsync.images.align.collections' => ['safety-shoes', 'safety-boots']]);
    app(ProductImageSync::class)->sync($link);

    $mains = fn () => VendorSync::product('SJ', '215754')->refresh()->getMedia('images')->filter(fn ($image): bool => $image->getCustomProperty('role') === 'main');

    expect($mains()->every(fn ($image): bool => $image->getCustomProperty('optimised') === 'webp-1200-q85-base15-top15-side4-ar100-grow-bg'))->toBeTrue();

    // With the list narrowed to another category, the same product is left as it comes: its main images are fetched again, plain.
    config(['vendorsync.images.align.collections' => ['safety-trainers']]);

    app(ProductImageSync::class)->sync($link->refresh());

    expect($mains()->every(fn ($image): bool => $image->getCustomProperty('optimised') === 'webp-1200-q85'))->toBeTrue();

    // A top level category means every category under it.
    config(['vendorsync.images.align.collections' => ['safety-footwear']]);

    app(ProductImageSync::class)->sync($link->refresh());

    expect($mains()->every(fn ($image): bool => $image->getCustomProperty('optimised') === 'webp-1200-q85-base15-top15-side4-ar100-grow-bg'))->toBeTrue();

    // Another top level category does not include it.
    config(['vendorsync.images.align.collections' => ['apparel']]);

    app(ProductImageSync::class)->sync($link->refresh());

    expect($mains()->every(fn ($image): bool => $image->getCustomProperty('optimised') === 'webp-1200-q85'))->toBeTrue();

    // An empty list means every category again.
    config(['vendorsync.images.align.collections' => []]);

    app(ProductImageSync::class)->sync($link->refresh());

    expect($mains()->every(fn ($image): bool => $image->getCustomProperty('optimised') === 'webp-1200-q85-base15-top15-side4-ar100-grow-bg'))->toBeTrue();
});

it('limits a run to the categories asked for, and does not even look at other products', function (): void {
    importBestboot();

    // BESTBOOT is a boot: another category queues nothing.
    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--category' => 'safety-trainers'])
        ->expectsOutputToContain('Queued 0 image jobs for SJ in safety-trainers.')
        ->assertSuccessful();

    // Its own categories queue it, one or both.
    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--category' => 'safety-shoes, safety-boots'])
        ->expectsOutputToContain('Queued 1 image job for SJ in safety-shoes, safety-boots.')
        ->assertSuccessful();

    // --aligned is the categories whose main images are put on the baseline (an empty list, the default, is every category).
    config(['vendorsync.images.align.collections' => ['safety-shoes', 'safety-boots']]);

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--aligned' => true])
        ->expectsOutputToContain('Queued 1 image job for SJ in safety-shoes, safety-boots.')
        ->assertSuccessful();

    // With --refresh the same limit applies.
    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--refresh' => true, '--category' => 'safety-trainers'])
        ->expectsOutputToContain('Queued 0 image jobs')
        ->assertSuccessful();

    // A top level category means everything under it: BESTBOOT is in a leaf of safety-footwear.
    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--category' => 'safety-footwear'])
        ->expectsOutputToContain('Queued 1 image job for SJ in safety-footwear.')
        ->assertSuccessful();

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--category' => 'apparel'])
        ->expectsOutputToContain('Queued 0 image jobs')
        ->assertSuccessful();

    // A handle that does not exist is refused before anything is queued.
    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--category' => 'safety-bots'])
        ->expectsOutputToContain("There is no category with the handle 'safety-bots'")
        ->assertFailed();
});

it('does not fetch an image that is already stored as WebP again', function (): void {
    $link = importBestboot();

    app(ProductImageSync::class)->sync($link);

    $ids = VendorSync::product('SJ', '215754')->getMedia('images')->pluck('id')->all();

    // A changed plan hash makes the sync look at every image, but each stored one is current.
    $link->refresh()->update(['image_urls_hash' => hash('sha256', 'another list')]);

    $result = app(ProductImageSync::class)->sync($link->refresh());

    expect($result->downloaded)->toBe(0)
        ->and(VendorSync::product('SJ', '215754')->getMedia('images')->pluck('id')->all())->toBe($ids);
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

    // Image jobs sit on the images queue, as the images supervisor works it.
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--queue' => 'images']);

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
    // The size limit is off by default; this test is about the optional one.
    config(['vendorsync.images.max_megapixels' => 7.0]);

    $link = importBestboot(brokenImages: ['HR0', 'OUTS', 'WEB'], hugeImages: ['BESTBOOT-BLK-HIRES.PNG']);

    expect(fn () => (new SyncProductImages($link->id))->handle(app(ProductImageSync::class)))
        ->toThrow(ImageSyncFailed::class, 'the image is 3692 x 3291 (12.2 megapixels, limit 7.0), and the smaller rendition');
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
        ->and(implode("\n", $run->messages))->toContain('Product 215754: PARTIAL, 4 of 6 images saved, 2 failed (0 lock timeouts, 0 not attempted)')
        ->and($run->stats['partial_jobs'])->toBe(1)
        ->and($run->stats['hero_failed_jobs'])->toBe(0)
        ->and($run->stats['images_failed'])->toBe(2)
        ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(4)
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('published')
        ->and($link->draft_reasons)->toBe([])
        ->and($link->images_synced_at)->not->toBeNull()
        ->and($link->image_urls_hash)->toHaveLength(64);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Product 215754: PARTIAL, 4 of 6 images saved'))->once();
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
    // The size limit is off by default; this test is about the optional one.
    config(['vendorsync.images.max_megapixels' => 7.0]);

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

/** Image requests recorded so far in this test. */
function imageRequestCount(): int
{
    return count(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/image/')));
}

it('keeps a product draft when its first image fails, and a later run completes it in the right order', function (): void {
    // The hero (position 0) is BESTBOOT-BLK-HIRES.PNG; the other five download.
    $link = importBestboot(brokenImages: ['BESTBOOT-BLK-HIRES']);

    $first = app(ProductImageSync::class)->sync($link);
    $link->refresh();
    $firstHash = $link->image_urls_hash;

    // Five images are stored, but another image does not stand in for the hero.
    expect($first->heroFailed)->toBeTrue()
        ->and($first->stored)->toBe(5)
        ->and($first->failed)->toHaveCount(1)
        ->and($first->failed[0])->toContain('BESTBOOT-BLK-HIRES.PNG: download failed: HTTP 404')
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('draft')
        ->and($link->draft_reasons)->toBe([DraftReason::NO_IMAGE])
        ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(5)
        // The incomplete hash makes the next run look at this product again.
        ->and($firstHash)->toHaveLength(64);

    // The host recovers. Only the missing hero is fetched.
    VendorSync::fakeHttp();
    $requestsBefore = imageRequestCount();

    $second = app(ProductImageSync::class)->sync($link->refresh());
    $link->refresh();
    $product = VendorSync::product('SJ', '215754');
    $media = $product->getMedia('images');
    $variantPrimary = $product->variants->first()->images()->wherePivot('primary', true)->get();
    $primaries = $media->filter(fn ($image): bool => (bool) $image->getCustomProperty('primary'));

    expect($second->heroFailed)->toBeFalse()
        ->and($second->downloaded)->toBe(1)
        ->and(imageRequestCount() - $requestsBefore)->toBe(1)
        ->and($second->stored)->toBe(6)
        // Final order: the hero first, then the details, then the outsole.
        ->and($media->map(fn ($image): string => $image->getCustomProperty('role'))->all())->toBe(['main', 'detail', 'detail', 'detail', 'detail', 'outsole'])
        ->and($media[0]->getCustomProperty('source_url'))->toEndWith('BESTBOOT-BLK-HIRES.PNG')
        // After the retry the product's one primary image is the hero.
        ->and($primaries)->toHaveCount(1)
        ->and($primaries->first()->getCustomProperty('source_url'))->toEndWith('BESTBOOT-BLK-HIRES.PNG')
        ->and($primaries->first()->is($media->first()))->toBeTrue()
        // Only the hero is primary, on the product and on the variant.
        ->and($media->map(fn ($image): bool => (bool) $image->getCustomProperty('primary'))->all())->toBe([true, false, false, false, false, false])
        ->and($variantPrimary)->toHaveCount(1)
        ->and($variantPrimary->first()->getCustomProperty('source_url'))->toEndWith('BESTBOOT-BLK-HIRES.PNG')
        // no_image is cleared and the product is published.
        ->and($link->draft_reasons)->toBe([])
        ->and($product->status)->toBe('published')
        ->and($link->image_urls_hash)->not->toBe($firstHash);
});

it('does not touch a published product when a refresh of its first image fails', function (): void {
    $link = importBestboot();
    app(ProductImageSync::class)->sync($link);
    expect(VendorSync::product('SJ', '215754')->status)->toBe('published');

    // A full refresh, with the hero's host down: the stored hero stays.
    VendorSync::fakeHttp(brokenImages: ['BESTBOOT-BLK-HIRES']);
    $result = app(ProductImageSync::class)->sync($link->refresh(), refresh: true);

    expect($result->heroFailed)->toBeFalse()
        ->and($result->failed)->toHaveCount(1)
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('published')
        ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(6);
});

it('reports a missing first image on every run for as long as it is missing', function (): void {
    Log::spy();
    $link = importBestboot(brokenImages: ['BESTBOOT-BLK-HIRES']);
    $hero = 'Product 215754: HERO IMAGE FAILED, left draft (no_image) until it downloads. 5 of 6 images saved, 1 failed (0 lock timeouts, 0 not attempted): ';

    // The first run and a second run with the host still broken say the same.
    foreach ([1, 2] as $attempt) {
        $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

        $run = imageRun();

        expect($run->status)->toBe('succeeded')
            ->and(implode("\n", $run->messages))->toContain($hero.'https://ws.safetyjogger.com/image/')
            ->and(implode("\n", $run->messages))->toContain('BESTBOOT-BLK-HIRES.PNG: download failed: HTTP 404')
            ->and($run->errors)->toBe(1)
            ->and($run->stats['partial_jobs'])->toBe(1)
            ->and($run->stats['hero_failed_jobs'])->toBe(1)
            ->and($run->stats['images_failed'])->toBe(1)
            ->and(VendorSync::product('SJ', '215754')->status)->toBe('draft')
            ->and($link->refresh()->draft_reasons)->toBe([DraftReason::NO_IMAGE])
            ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(5)
            ->and(SyncRun::query()->where('type', 'images')->count())->toBe($attempt);
    }

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'HERO IMAGE FAILED'))->twice();

    // The status command states it too, as the message list is cut short.
    $this->artisan('vendorsync:status')
        ->expectsOutputToContain('1 partial, 1 with the first image missing (left draft); 1 images failed (0 lock timeouts, 0 not attempted); 0 failed jobs')
        ->assertSuccessful();

    // The host recovers: the third run completes the product and the line is gone.
    VendorSync::fakeHttp();
    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

    expect(imageRun()->messages ?? [])->toBe([])
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('published')
        ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(6);
});

it('fails the job and counts every lock timeout when the host throttle lock stays busy', function (): void {
    $link = importBestboot();
    freezeClock();

    // Another worker (or a stuck process) holds the host's slot lock.
    $held = Cache::lock(app(ImageDownloader::class)->lockKey('ws.safetyjogger.com'), 600);
    expect($held->get())->toBeTrue();

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

    $run = imageRun();

    // No request was made, nothing was saved, and the report says why.
    expect($run->status)->toBe('failed')
        ->and($run->errors)->toBe(1)
        ->and($run->stats['failed_jobs'])->toBe(1)
        ->and($run->stats['lock_timeouts'])->toBe(6)
        ->and($run->stats['images_failed'])->toBe(6)
        ->and($run->messages[0])->toContain('none of the 6 images could be saved')
        ->and($run->messages[0])->toContain('download failed: could not reserve a request slot on ws.safetyjogger.com within 5s (host throttle lock busy)')
        ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(0)
        ->and($link->refresh()->images_synced_at)->toBeNull();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/image/'));
});

it('counts a lock timeout on the first image as a failed hero, and the product stays draft', function (): void {
    $link = importBestboot();
    freezeClock();
    config(['vendorsync.images.slot_lock_seconds' => 2]);

    // The lock is held for 3 s: the first image gives up after 2 s, the second finds it free.
    $held = Cache::lock(app(ImageDownloader::class)->lockKey('ws.safetyjogger.com'), 3);
    expect($held->get())->toBeTrue();

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

    $run = imageRun();

    expect($run->status)->toBe('succeeded')
        ->and($run->messages[0])->toContain('Product 215754: HERO IMAGE FAILED, left draft (no_image) until it downloads. 5 of 6 images saved, 1 failed (1 lock timeouts, 0 not attempted)')
        ->and($run->messages[0])->toContain('could not reserve a request slot on ws.safetyjogger.com within 2s')
        ->and($run->stats['lock_timeouts'])->toBe(1)
        ->and($run->stats['hero_failed_jobs'])->toBe(1)
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('draft')
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('reports a partial result, with the rest not attempted, when the time budget runs out', function (): void {
    $link = importBestboot();
    freezeClock();
    // Every image takes 100 s to arrive.
    VendorSync::slowImages(['BESTBOOT'], 100);

    $run = app(RunRecorder::class)->start($link->vendor, ImageRun::TYPE);
    $job = new SyncProductImages($link->id, false, $run->id);
    // A 400 s timeout minus the 60 s margin leaves 340 s: the second image
    // starts with 240 s left, the third with 140 s (197 s are needed).
    $job->timeout = 400;
    $job->handle(app(ProductImageSync::class));

    $run->refresh();

    expect($run->messages[0])->toContain('Product 215754: PARTIAL, 2 of 6 images saved, 4 failed (0 lock timeouts, 4 not attempted)')
        ->and($run->messages[0])->toContain('not attempted: job time budget exhausted (140s left, a download can take up to 197s)')
        ->and($run->stats['not_attempted'])->toBe(4)
        ->and($run->stats['partial_jobs'])->toBe(1)
        ->and($run->stats['hero_failed_jobs'])->toBe(0)
        // The hero was saved, so the product is published with what it has.
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('published')
        ->and(VendorSync::product('SJ', '215754')->getMedia('images'))->toHaveCount(2);
});

it('keeps a published product published, with its existing hero, when a changed hero URL fails to download', function (): void {
    Log::spy();
    $link = importBestboot();
    app(ProductImageSync::class)->sync($link);

    // The vendor moved the hero: the stored hero came from an older URL, and
    // the URL the list gives now is down.
    $oldHero = VendorSync::product('SJ', '215754')->getMedia('images')->first();
    $oldHero->setCustomProperty('source_url', 'https://ws.safetyjogger.com/image/general/SAFETY/OLD-HERO.JPG')->save();
    $link->update(['image_urls_hash' => null]);
    VendorSync::fakeHttp(brokenImages: ['BESTBOOT-BLK-HIRES']);

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();

    $run = imageRun();
    $product = VendorSync::product('SJ', '215754');
    $media = $product->getMedia('images');
    $messages = implode("\n", $run->messages);

    expect($run->status)->toBe('succeeded')
        // A distinct line, not the draft wording, and not counted as a held-back hero.
        ->and($messages)->toContain('Product 215754: hero refresh failed, kept existing image: https://ws.safetyjogger.com/image/')
        ->and($messages)->toContain('BESTBOOT-BLK-HIRES.PNG: download failed: HTTP 404')
        ->and($messages)->not->toContain('left draft')
        ->and($messages)->not->toContain('HERO IMAGE FAILED')
        ->and($messages)->not->toContain('PARTIAL')
        ->and($run->stats['hero_refresh_failed_jobs'])->toBe(1)
        ->and($run->stats['hero_failed_jobs'])->toBe(0)
        ->and($run->stats['partial_jobs'])->toBe(0)
        ->and($run->stats['images_failed'])->toBe(1)
        // The product is untouched: published, no_image absent, the old hero first and the only primary.
        ->and($product->status)->toBe('published')
        ->and($link->refresh()->draft_reasons)->toBe([])
        ->and($media)->toHaveCount(6)
        ->and($media->first()->is($oldHero))->toBeTrue()
        ->and($media->filter(fn ($image): bool => (bool) $image->getCustomProperty('primary')))->toHaveCount(1)
        ->and($media->first()->getCustomProperty('primary'))->toBeTrue()
        ->and($product->variants->first()->images()->wherePivot('primary', true)->pluck('media.id')->all())->toBe([$oldHero->id])
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'hero refresh failed, kept existing image'))->once();
    Log::shouldNotHaveReceived('error');
});

it('holds a product back when its first image cannot be saved to the media library', function (): void {
    $link = importBestboot();
    $model = config('media-library.media_model');

    // The first image downloads, then the media library refuses it (a full disk, say).
    $model::creating(function ($media): void {
        if (str_contains((string) ($media->custom_properties['source_url'] ?? ''), 'BESTBOOT-BLK-HIRES')) {
            throw new RuntimeException('disk full');
        }
    });

    $result = app(ProductImageSync::class)->sync($link);

    expect($result->heroFailed)->toBeTrue()
        ->and($result->heroKept)->toBeFalse()
        ->and($result->stored)->toBe(5)
        ->and($result->failed)->toHaveCount(1)
        ->and($result->failed[0])->toContain('BESTBOOT-BLK-HIRES.PNG: RuntimeException: disk full')
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('draft')
        ->and($link->refresh()->draft_reasons)->toBe([DraftReason::NO_IMAGE]);
});

it('holds a product back, and stores nothing, when its first image fails and no other image can be saved', function (): void {
    $link = importBestboot(brokenImages: ['BESTBOOT']);

    $result = app(ProductImageSync::class)->sync($link);
    $link->refresh();

    expect($result->heroFailed)->toBeTrue()
        ->and($result->stored)->toBe(0)
        ->and($result->failed)->toHaveCount(6)
        ->and(VendorSync::product('SJ', '215754')->status)->toBe('draft')
        ->and($link->draft_reasons)->toBe([DraftReason::NO_IMAGE])
        ->and($link->image_urls_hash)->toBeNull()
        ->and($link->images_synced_at)->toBeNull();
});
