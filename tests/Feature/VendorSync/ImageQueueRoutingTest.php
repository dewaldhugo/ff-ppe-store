<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\ImageRun;
use App\Domain\VendorSync\Images\SyncProductImages;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Support\Queues;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\VendorSync;

/*
 * Image jobs must land on the `images` queue, the only one whose Horizon
 * supervisor has a timeout above the job's. The bug these tests pin down: the
 * job set its own queue, but a batch ignores that and pushes to the
 * connection default unless the batch itself names the queue. Every entry
 * point that queues image jobs is checked against a real queue table, not the
 * job's property.
 */

beforeEach(function (): void {
    VendorSync::boot();
    VendorSync::fakeHttp();
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));

    // Footsure has no API: its catalogue needs no HTTP, so it can be imported
    // and its image jobs queued without a fake vendor.
    VendorSync::footsureFile();
    VendorSync::import('FS');

    config()->set('queue.default', 'database');
    DB::table('jobs')->delete();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return list<string> The queue of every job in the `jobs` table.
 */
function queuedQueues(): array
{
    return DB::table('jobs')->pluck('queue')->all();
}

/**
 * @return list<string> The class of every job in the `jobs` table.
 */
function queuedJobClasses(): array
{
    return DB::table('jobs')->pluck('payload')
        ->map(fn (string $payload): string => json_decode($payload, true)['displayName'])
        ->all();
}

it('queues image jobs on the images queue from every entry point', function (string $command, array $arguments): void {
    $first = (string) VendorProduct::query()->orderBy('id')->value('vendor_product_id');
    $arguments = array_map(fn (mixed $value): mixed => $value === '{first}' ? $first : $value, $arguments);

    if ($command === 'vendorsync:catalogue') {
        VendorSync::footsureFile();
    }

    $this->artisan($command, $arguments)->assertSuccessful();

    $queues = queuedQueues();

    expect($queues)->not->toBeEmpty()
        ->and(array_unique($queues))->toBe([Queues::IMAGES])
        ->and(array_unique(queuedJobClasses()))->toBe([SyncProductImages::class]);
})->with([
    'vendorsync:images with --product' => ['vendorsync:images', ['vendor' => 'FS', '--product' => '{first}']],
    'vendorsync:images with --product and --refresh' => ['vendorsync:images', ['vendor' => 'FS', '--product' => '{first}', '--refresh' => true]],
    'vendorsync:images for the whole vendor' => ['vendorsync:images', ['vendor' => 'FS']],
    'vendorsync:images --refresh, as scheduled' => ['vendorsync:images', ['vendor' => 'FS', '--refresh' => true]],
    'the vendorsync:catalogue image step' => ['vendorsync:catalogue', ['vendor' => 'FS', '--force' => true]],
]);

it('queues image jobs on the images queue when ImageRun is called directly', function (): void {
    $queued = app(ImageRun::class)->dispatch('FS');

    expect($queued)->toBeGreaterThan(0)
        ->and(queuedQueues())->toHaveCount($queued)
        ->and(array_unique(queuedQueues()))->toBe([Queues::IMAGES]);
});

it('queues a directly dispatched image job on the images queue', function (): void {
    Queue::fake();

    SyncProductImages::dispatch(1);

    Queue::assertPushedOn(Queues::IMAGES, SyncProductImages::class);
});
