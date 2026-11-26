<?php

declare(strict_types=1);

use App\Domain\VendorSync\Contracts\VendorSource;
use App\Domain\VendorSync\Data\SourceBatch;
use App\Domain\VendorSync\Exceptions\RunAlreadyRunning;
use App\Domain\VendorSync\Images\ImageRun;
use App\Domain\VendorSync\Import\CatalogueImporter;
use App\Domain\VendorSync\Import\RunRecorder;
use App\Domain\VendorSync\Import\StockSync;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Notifications\SyncRunAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Lunar\Models\Product;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
    VendorSync::fakeHttp();
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));
    Cache::flush();
});

function lockName(string $vendor, string $type): string
{
    return "vendorsync.run.{$vendor}.{$type}";
}

function leftoverRun(string $vendorCode, string $type, string $startedAgo = '3 hours'): SyncRun
{
    $vendor = app(RunRecorder::class)->vendor($vendorCode);

    return SyncRun::query()->create([
        'vendor_id' => $vendor->id,
        'type' => $type,
        'status' => 'running',
        'started_at' => now()->sub($startedAgo),
    ]);
}

it('marks a run left as running as failed when the next run starts, and alerts', function (): void {
    $stale = leftoverRun('FS', 'catalogue');
    $other = leftoverRun('SJ', 'catalogue');

    VendorSync::footsureFile();
    $report = VendorSync::import('FS');

    expect($report->status)->toBe('succeeded')
        ->and($stale->refresh()->status)->toBe('failed')
        ->and($stale->finished_at)->not->toBeNull()
        ->and($stale->messages[0])->toContain('Marked failed: this run never finished')
        // Another vendor's run is not ours to fail.
        ->and($other->refresh()->status)->toBe('running')
        ->and(SyncRun::query()->where('type', 'catalogue')->where('status', 'succeeded')->count())->toBe(1);

    Notification::assertSentOnDemand(SyncRunAlert::class);
});

it('refuses a second run while the lock is held and leaves no record of it', function (): void {
    $held = Cache::lock(lockName('FS', 'catalogue'), 600);
    expect($held->get())->toBeTrue();

    $running = leftoverRun('FS', 'catalogue', '5 minutes');
    VendorSync::footsureFile();

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('aborted')
        ->and($report->messages[0])->toBe('Another catalogue run for FS is already running. This run was not started.')
        ->and(Product::query()->count())->toBe(0)
        // The run that holds the lock is untouched, and the refused one left no row.
        ->and($running->refresh()->status)->toBe('running')
        ->and(SyncRun::query()->count())->toBe(1)
        ->and(glob(VendorSync::path('incoming').'/*.csv'))->toHaveCount(1);

    // The refused run did not release a lock it never held.
    expect(Cache::lock(lockName('FS', 'catalogue'), 60)->get())->toBeFalse();

    $held->release();

    expect(VendorSync::import('FS')->status)->toBe('succeeded');
});

it('releases the lock however the run ends', function (string $how): void {
    $source = null;

    if ($how === 'stale file') {
        Carbon::setTestNow(Carbon::parse('2026-11-20 07:20:00', 'Europe/London'));
        VendorSync::footsureFile();
        $status = 'aborted';
    } elseif ($how === 'exception') {
        $source = new class implements VendorSource
        {
            public function fetch(): SourceBatch
            {
                throw new RuntimeException('the source exploded');
            }

            public function completed(): void {}
        };
        $status = 'failed';
    } else {
        VendorSync::footsureFile();
        $status = 'succeeded';
    }

    $report = app(CatalogueImporter::class)->run('FS', source: $source);

    expect($report->status)->toBe($status);

    $lock = Cache::lock(lockName('FS', 'catalogue'), 60);

    expect($lock->get())->toBeTrue();

    $lock->release();
})->with(['success', 'stale file', 'exception']);

it('keeps the lock for at least two hours, whatever the config says', function (): void {
    config()->set('vendorsync.lock_seconds', 60);

    expect(app(RunRecorder::class)->lockSeconds())->toBe(7200);

    config()->set('vendorsync.lock_seconds', 4 * 3600);

    expect(app(RunRecorder::class)->lockSeconds())->toBe(14400)
        ->and(config('vendorsync.lock_seconds'))->toBeGreaterThanOrEqual(7200);
});

it('does not let a dry run take the lock or be blocked by it', function (): void {
    $held = Cache::lock(lockName('FS', 'catalogue'), 600);
    $held->get();

    VendorSync::footsureFile();

    $report = VendorSync::import('FS', dryRun: true);

    expect($report->status)->toBe('succeeded')
        ->and($report->created)->toBe(29)
        ->and(SyncRun::query()->count())->toBe(0);

    $held->release();
    VendorSync::import('FS', dryRun: true);

    // A dry run takes no lock, so a real run can start straight afterwards.
    expect(Cache::lock(lockName('FS', 'catalogue'), 60)->get())->toBeTrue();
});

it('applies the same lock and leftover rule to stock runs', function (): void {
    VendorSync::fakeHttp(stock: []);
    $stale = leftoverRun('SJ', 'stock');

    $report = app(StockSync::class)->run('SJ');

    expect($report->status)->toBe('succeeded')
        ->and($stale->refresh()->status)->toBe('failed');

    $held = Cache::lock(lockName('SJ', 'stock'), 600);
    $held->get();

    $refused = app(StockSync::class)->run('SJ');

    expect($refused->status)->toBe('aborted')
        ->and($refused->messages[0])->toBe('Another stock run for SJ is already running. This run was not started.');
});

it('only fails an image run that is older than the lock, since image runs finish asynchronously', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $young = leftoverRun('FS', 'images', '30 minutes');
    $old = leftoverRun('FS', 'images', '5 hours');

    app(ImageRun::class)->dispatch('FS', 'no-such-product');

    expect($young->refresh()->status)->toBe('running')
        ->and($old->refresh()->status)->toBe('failed')
        ->and($old->messages[0])->toContain('Marked failed');

    // Image queuing is refused while another dispatch holds the lock.
    $held = Cache::lock(lockName('FS', 'images'), 600);
    $held->get();

    expect(fn () => app(ImageRun::class)->dispatch('FS', 'no-such-product'))
        ->toThrow(RunAlreadyRunning::class, 'Another images run for FS is already running');

    $this->artisan('vendorsync:images', ['vendor' => 'FS'])
        ->expectsOutputToContain('already running')
        ->assertFailed();

    expect(VendorProduct::query()->count())->toBe(32);
});

it('queues no image jobs with --without-images or when queue_images is off, and says how to queue them later', function (): void {
    config()->set('queue.default', 'sync');
    VendorSync::footsureFile();

    $this->artisan('vendorsync:catalogue', ['vendor' => 'FS', '--without-images' => true])
        ->expectsOutputToContain('Image jobs were not queued (--without-images). Queue them when ready: php artisan vendorsync:images FS')
        ->assertSuccessful();

    expect(SyncRun::query()->where('type', 'images')->count())->toBe(0);

    config()->set('vendorsync.queue_images', false);
    VendorSync::footsureFile();

    $this->artisan('vendorsync:catalogue', ['vendor' => 'FS', '--force' => true])
        ->expectsOutputToContain('VENDORSYNC_QUEUE_IMAGES is false')
        ->assertSuccessful();

    expect(SyncRun::query()->where('type', 'images')->count())->toBe(0);
});
