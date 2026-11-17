<?php

declare(strict_types=1);

use App\Domain\VendorSync\Exceptions\ImageSyncFailed;
use App\Domain\VendorSync\Images\ProductImageSync;
use App\Domain\VendorSync\Images\SyncProductImages;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Notifications\SyncRunAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Lunar\Models\Product;
use Tests\Support\FlakySearchEngine;
use Tests\Support\VendorSync;

/*
 * The image sync used to write its hash, publish the product and only then
 * call the search index. An indexing failure left a published product with
 * a draft index entry, and the next run saw the hash up to date and never
 * reindexed. The hash is now cleared before the status change and the index
 * call, and written back only after the index call succeeds.
 */

beforeEach(function (): void {
    VendorSync::boot();
    Notification::fake();
});

/**
 * Import BESTBOOT [215754]: draft, waiting for its first image.
 */
function indexBoot(): VendorProduct
{
    VendorSync::fakeHttp([['id' => 215754]], [215754 => VendorSync::json('SafetyJogger/product-215754.json')]);
    VendorSync::import('SJ');

    return VendorProduct::query()->where('vendor_product_id', '215754')->sole();
}

function indexBootProduct(): Product
{
    return VendorSync::product('SJ', '215754');
}

/**
 * Image downloads made so far.
 */
function indexImageRequests(): int
{
    return Http::recorded()->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/image/'))->count();
}

/**
 * Put the product back to "waiting for an image" without touching its media
 * or its hash, with Scout and model events out of the way.
 */
function indexMakeDraft(VendorProduct $link): void
{
    Product::query()->whereKey($link->product_id)->update(['status' => 'draft']);
    $link->update(['draft_reasons' => [DraftReason::NO_IMAGE]]);
}

it('keeps the product published, fails the job and says only indexing failed when the index call throws', function (): void {
    $link = indexBoot();
    $engine = FlakySearchEngine::install(failAll: true);

    // Through a real queue and worker, as locally and in production.
    config()->set('queue.default', 'database');

    $this->artisan('vendorsync:images', ['vendor' => 'SJ', '--product' => '215754'])->assertSuccessful();
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--queue' => 'images']);

    $run = SyncRun::query()->where('type', 'images')->latest('id')->firstOrFail();
    $link->refresh();

    // The images and the status are in the database.
    expect(indexBootProduct()->status)->toBe('published')
        ->and(indexBootProduct()->getMedia('images'))->toHaveCount(6)
        ->and($link->draft_reasons)->toBe([])
        ->and($link->images_synced_at)->not->toBeNull()
        // The hash is cleared, so the next run does not treat the product as done.
        ->and($link->image_urls_hash)->toBeNull()
        // One index call, and it failed.
        ->and($engine->batches)->toBe([[$link->product_id]]);

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('6 of 6 images saved, but search indexing failed (RuntimeException: Meilisearch is down)')
        ->and($run->status)->toBe('failed')
        ->and($run->errors)->toBe(1)
        ->and($run->stats['failed_jobs'])->toBe(1)
        ->and($run->stats['index_failed_jobs'])->toBe(1)
        ->and($run->messages[0])->toContain('The database is up to date and only indexing failed')
        ->and($run->messages[0])->toContain('without downloading the stored images again');

    // Every job failed, so the run alerts, like a failed catalogue run.
    Notification::assertSentOnDemand(SyncRunAlert::class);
});

it('reindexes on the next run from the stored images, with no downloads', function (): void {
    $link = indexBoot();
    FlakySearchEngine::install(failAll: true);

    expect(fn () => (new SyncProductImages($link->id))->handle(app(ProductImageSync::class)))
        ->toThrow(ImageSyncFailed::class, 'only indexing failed');

    $downloads = indexImageRequests();
    $engine = FlakySearchEngine::install();

    (new SyncProductImages($link->id))->handle(app(ProductImageSync::class));

    $link->refresh();

    expect(indexImageRequests())->toBe($downloads)
        ->and($engine->batches)->toBe([[$link->product_id]])
        ->and(indexBootProduct()->status)->toBe('published')
        ->and(indexBootProduct()->getMedia('images'))->toHaveCount(6)
        ->and($link->image_urls_hash)->toHaveLength(64);

    // And now it is done: another run does nothing.
    $again = FlakySearchEngine::install();
    (new SyncProductImages($link->id))->handle(app(ProductImageSync::class));

    expect($again->batches)->toBe([])->and(indexImageRequests())->toBe($downloads);
});

it('sends the product to the index once, from outside any database transaction', function (): void {
    $link = indexBoot();
    $engine = FlakySearchEngine::install();
    $baseline = DB::transactionLevel();

    app(ProductImageSync::class)->sync($link);

    // One call, made by the sync itself: the status save did not index too.
    expect($engine->batches)->toBe([[$link->product_id]])
        ->and($engine->levels)->toBe([$baseline]);
});

it('clears a hash that would not change, so a refresh that fails to index is retried', function (): void {
    $link = indexBoot();
    app(ProductImageSync::class)->sync($link);
    $planHash = $link->refresh()->image_urls_hash;

    // Make the next refresh replace one image, which changes the product.
    $media = indexBootProduct()->getMedia('images')->first();
    $media->setCustomProperty('sha256', 'stale')->save();

    FlakySearchEngine::install(failAll: true);
    $result = app(ProductImageSync::class)->sync($link->refresh(), refresh: true);

    // The new hash would equal the stored one, which is why it is cleared first.
    expect($result->indexFailure)->toContain('Meilisearch is down')
        ->and($result->downloaded)->toBe(1)
        ->and($link->refresh()->image_urls_hash)->toBeNull();

    $downloads = indexImageRequests();
    $engine = FlakySearchEngine::install();

    app(ProductImageSync::class)->sync($link->refresh());

    expect(indexImageRequests())->toBe($downloads)
        ->and($engine->batches)->toBe([[$link->product_id]])
        ->and($link->refresh()->image_urls_hash)->toBe($planHash);
});

it('does not index a refresh that changed nothing', function (): void {
    $link = indexBoot();
    app(ProductImageSync::class)->sync($link);
    $planHash = $link->refresh()->image_urls_hash;

    $engine = FlakySearchEngine::install();
    $result = app(ProductImageSync::class)->sync($link->refresh(), refresh: true);

    expect($result->changed)->toBeFalse()
        ->and($result->downloaded)->toBe(0)
        ->and($engine->batches)->toBe([])
        ->and($link->refresh()->image_urls_hash)->toBe($planHash);
});

it('indexes a product that is draft with no_image even when the hash matches and nothing else changed', function (): void {
    $link = indexBoot();
    app(ProductImageSync::class)->sync($link);
    $planHash = $link->refresh()->image_urls_hash;

    // Images present, hash current, but the product is still waiting.
    indexMakeDraft($link);

    $engine = FlakySearchEngine::install();
    $result = app(ProductImageSync::class)->sync($link->refresh(), refresh: true);

    expect($link->image_urls_hash)->toBe($planHash)
        ->and($result->downloaded)->toBe(0)
        // The status change was made by refreshStatus(), not by media changes.
        ->and($result->changed)->toBeTrue()
        ->and(indexBootProduct()->status)->toBe('published')
        ->and($link->refresh()->draft_reasons)->toBe([])
        ->and($engine->batches)->toBe([[$link->product_id]])
        ->and($link->image_urls_hash)->toBe($planHash);
});

it('leaves the hash cleared when the sync stops after the invalidation and before the index call, and the next run reindexes', function (): void {
    $link = indexBoot();
    app(ProductImageSync::class)->sync($link);
    $planHash = $link->refresh()->image_urls_hash;
    indexMakeDraft($link);

    // refreshStatus() saves the product: make that throw, after the hash was cleared.
    $broken = true;
    Product::updating(function () use (&$broken): void {
        if ($broken) {
            throw new RuntimeException('connection lost');
        }
    });

    $engine = FlakySearchEngine::install();

    expect(fn () => app(ProductImageSync::class)->sync($link->refresh(), refresh: true))
        ->toThrow(RuntimeException::class, 'connection lost');

    // Without the write-ahead the hash would still be the plan hash, and the
    // product would be skipped for ever.
    expect($link->refresh()->image_urls_hash)->toBeNull()
        ->and(indexBootProduct()->status)->toBe('draft')
        ->and($engine->batches)->toBe([]);

    $broken = false;
    $downloads = indexImageRequests();

    (new SyncProductImages($link->id))->handle(app(ProductImageSync::class));

    expect(indexImageRequests())->toBe($downloads)
        ->and(indexBootProduct()->status)->toBe('published')
        ->and($engine->batches)->toBe([[$link->product_id]])
        ->and($link->refresh()->image_urls_hash)->toBe($planHash);
});

it('succeeds when Scout queueing is on, because the index call only queues a job', function (): void {
    $link = indexBoot();
    config()->set('scout.queue', true);
    Queue::fake([MakeSearchable::class]);
    $engine = FlakySearchEngine::install(failAll: true);

    (new SyncProductImages($link->id))->handle(app(ProductImageSync::class));

    // The engine is never called from this job, so an engine failure would
    // surface in the queued job, not here (see docs/vendorsync.md).
    Queue::assertPushed(MakeSearchable::class);
    expect($engine->batches)->toBe([])
        ->and(indexBootProduct()->status)->toBe('published')
        ->and($link->refresh()->image_urls_hash)->toHaveLength(64);
});
