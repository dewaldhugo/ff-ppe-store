<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
    Notification::fake();
    config()->set('vendorsync.queue_images', false);
});

function fakeTwoProducts(): void
{
    VendorSync::fakeHttp(
        [['id' => 701], ['id' => 702]],
        [
            701 => VendorSync::detail(701, 'WORDING1', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022']),
            702 => VendorSync::detail(702, 'WORDING2', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022']),
        ],
    );
}

it('says the products were queued for indexing when Scout queueing is on', function (): void {
    config(['scout.queue' => true]);
    Queue::fake();
    fakeTwoProducts();

    $report = VendorSync::import('SJ');

    expect($report->indexing)->toBe('2 products queued for indexing')
        ->and($report->toArray()['indexing'])->toBe('2 products queued for indexing')
        ->and(json_encode($report->toArray()))->not->toContain('reindexed');

    Queue::assertPushed(MakeSearchable::class);
});

it('says the products were sent to the search index, and to verify it, when Scout queueing is off', function (): void {
    config(['scout.queue' => false]);
    fakeTwoProducts();

    $report = VendorSync::import('SJ');

    expect($report->indexing)->toBe('2 products sent to the search index (applied asynchronously; run catalogue:verify-index)')
        ->and($report->indexing)->not->toContain('reindexed');
});

it('prints the wording in the command output in both modes, and never says reindexed', function (bool $queued, string $line): void {
    config(['scout.queue' => $queued]);
    Queue::fake();
    fakeTwoProducts();

    $this->artisan('vendorsync:catalogue SJ --without-images')
        ->expectsOutputToContain($line)
        ->doesntExpectOutputToContain('reindexed')
        ->assertSuccessful();
})->with([
    'queued' => [true, '2 products queued for indexing'],
    'synchronous' => [false, '2 products sent to the search index (applied asynchronously; run catalogue:verify-index)'],
]);

it('has no indexing line when the run wrote nothing', function (): void {
    fakeTwoProducts();
    VendorSync::import('SJ');

    $second = VendorSync::import('SJ');

    expect($second->unchanged)->toBe(2)->and($second->indexing)->toBeNull();
});
