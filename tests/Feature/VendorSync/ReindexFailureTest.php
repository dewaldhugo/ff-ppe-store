<?php

declare(strict_types=1);

use App\Domain\VendorSync\Import\RunReport;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Notifications\SyncRunAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FlakySearchEngine;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
    Notification::fake();
    config()->set('vendorsync.queue_images', false);
    config()->set('vendorsync.markup', '1.5');
    config()->set('vendorsync.vendors.SJ.markup', null);
    config()->set('vendorsync.price_change_guard_percent', 40);
    // One product per chunk, so five products are five chunks.
    config()->set('vendorsync.reindex_chunk', 1);
});

/**
 * Five Safety Jogger products, product 601 first.
 */
function importFive(float $cost = 20.0): RunReport
{
    $ids = [601, 602, 603, 604, 605];

    VendorSync::fakeHttp(
        array_map(fn (int $id): array => ['id' => $id], $ids),
        collect($ids)->mapWithKeys(fn (int $id): array => [$id => VendorSync::detail($id, "BOOT{$id}", 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022'], cost: $cost)])->all(),
    );

    return VendorSync::import('SJ');
}

/**
 * @return array<int, ?string> Payload hash by Lunar product ID.
 */
function hashes(): array
{
    return VendorProduct::query()->pluck('payload_hash', 'product_id')->all();
}

it('sends no product to the search index from inside a database transaction', function (): void {
    $engine = FlakySearchEngine::install();
    $baseline = DB::transactionLevel();

    $report = importFive();

    // One call per chunk, all after the writes: nothing was indexed row by row.
    expect($report->ok())->toBeTrue()
        ->and($engine->batches)->toHaveCount(5)
        ->and(array_unique($engine->levels))->toBe([$baseline])
        ->and($report->reindexedProductIds)->toBe($report->productIds);
});

it('reports a failed chunk, keeps going, fails the run and clears only the unindexed hashes', function (): void {
    $engine = FlakySearchEngine::install(failBatches: [2]);

    $report = importFive();
    [$first, $second, $third, $fourth, $fifth] = $report->productIds;

    expect($report->ok())->toBeFalse()
        ->and($report->status)->toBe(RunReport::FAILED)
        ->and($report->created)->toBe(5)
        // The other four chunks were still tried after the failure.
        ->and($engine->batches)->toHaveCount(5)
        ->and($report->reindexedProductIds)->toBe([$first, $third, $fourth, $fifth])
        ->and($report->errors)->toBe(1)
        ->and($report->messages[0])->toContain('1 of 5 written products are not in the search index')
        ->and($report->messages[0])->toContain('All database writes succeeded; only indexing failed')
        ->and(implode("\n", $report->messages))->toContain("Lunar product IDs {$second} to {$second}, chunk 2 of 5")
        ->and(implode("\n", $report->messages))->toContain('RuntimeException: Meilisearch is down');

    // All five products are in the database, published or drafted as usual.
    expect(VendorProduct::query()->count())->toBe(5)
        ->and(hashes()[$second])->toBeNull()
        ->and(hashes()[$first])->not->toBeNull()
        ->and(hashes()[$third])->not->toBeNull()
        ->and(hashes()[$fourth])->not->toBeNull()
        ->and(hashes()[$fifth])->not->toBeNull();

    // The run record says failed, so the alert goes out.
    expect(SyncRun::query()->latest('id')->first()->status)->toBe('failed');
    Notification::assertSentOnDemand(SyncRunAlert::class);
});

it('rewrites and reindexes exactly the products that were not indexed on the next run', function (): void {
    FlakySearchEngine::install(failBatches: [2]);
    $failed = importFive();
    $second = $failed->productIds[1];

    $engine = FlakySearchEngine::install();
    $next = importFive();

    expect($next->ok())->toBeTrue()
        ->and($next->updated)->toBe(1)
        ->and($next->unchanged)->toBe(4)
        ->and($engine->batches)->toBe([[$second]])
        ->and($next->reindexedProductIds)->toBe([$second])
        ->and(hashes()[$second])->not->toBeNull();
});

it('stops after three failed chunks in a row and clears the hashes of the failed and the unattempted', function (): void {
    $engine = FlakySearchEngine::install(failAll: true);

    $report = importFive();

    expect($report->status)->toBe(RunReport::FAILED)
        ->and($engine->batches)->toHaveCount(3)
        ->and($report->errors)->toBe(3)
        ->and($report->reindexedProductIds)->toBe([])
        ->and($report->messages[0])->toContain('5 of 5 written products are not in the search index (3 in failed chunks, 2 not attempted after 3 failed chunks in a row)')
        ->and(array_filter(hashes(), fn (?string $hash): bool => $hash !== null))->toBe([]);

    FlakySearchEngine::install();
    $next = importFive();

    expect($next->ok())->toBeTrue()->and($next->updated)->toBe(5);
});

it('does not count failures that are not in a row towards the cutoff', function (): void {
    // Chunks 1, 2, 4 and 5 fail; 3 succeeds: never three in a row.
    $engine = FlakySearchEngine::install(failBatches: [1, 2, 4, 5]);

    $report = importFive();

    expect($engine->batches)->toHaveCount(5)
        ->and($report->errors)->toBe(4)
        ->and($report->messages[0])->toContain('4 of 5 written products are not in the search index (4 in failed chunks, 0 not attempted');
});

it('still decides create or update from the stored link, and holds a big cost change after a hash was cleared', function (): void {
    FlakySearchEngine::install(failAll: true);
    importFive(cost: 10.0);

    // The hash is gone, the product and its stored cost are not.
    expect(array_filter(hashes(), fn (?string $hash): bool => $hash !== null))->toBe([]);

    // Healthy engine, vendor cost +50 percent: an update, held by the guard.
    FlakySearchEngine::install();
    $report = importFive(cost: 15.0);

    expect($report->created)->toBe(0)
        ->and($report->updated)->toBe(5)
        ->and($report->held)->toHaveCount(5)
        ->and(VendorOffer::query()->pluck('cost_price')->unique()->all())->toBe([1000])
        // Held products keep the (null) hash, so they come back next run.
        ->and(array_filter(hashes(), fn (?string $hash): bool => $hash !== null))->toBe([]);

    // A small cost change goes through on the next run.
    $accepted = importFive(cost: 11.0);

    expect($accepted->held)->toBe([])
        ->and(VendorOffer::query()->pluck('cost_price')->unique()->all())->toBe([1100]);
});
