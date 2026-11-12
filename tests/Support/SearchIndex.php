<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Lunar\Models\Product;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;
use RuntimeException;
use Throwable;

/**
 * Gives search tests a real Meilisearch index, under the `testing_` prefix
 * set in phpunit.xml so the development index is never touched.
 *
 * The index is rebuilt from the seeded catalogue once per test run.
 */
final class SearchIndex
{
    private static bool $built = false;

    public static function use(): void
    {
        // Everything else in the suite runs on the null driver.
        config(['scout.driver' => 'meilisearch']);

        if (self::$built) {
            return;
        }

        $client = app(Client::class);
        $uid = (new Product)->searchableAs();

        if (! str_starts_with($uid, 'testing_')) {
            throw new RuntimeException("Refusing to rebuild the search index [{$uid}]: it is not a testing index.");
        }

        try {
            $client->health();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Search tests need the local Meilisearch instance ('.config('scout.meilisearch.host').'). Start it and check MEILISEARCH_KEY in .env.',
                previous: $exception,
            );
        }

        $client->waitForTask($client->deleteIndex($uid)['taskUid'], 30000);

        Artisan::call('catalogue:sync-search-settings');
        Product::query()->get()->searchable();

        self::settle();
        self::$built = true;
    }

    /**
     * Block until Meilisearch has processed every queued task for the index.
     */
    public static function settle(): void
    {
        $client = app(Client::class);
        $uid = (new Product)->searchableAs();
        $deadline = microtime(true) + 30;

        do {
            $pending = $client->getTasks(
                (new TasksQuery)->setIndexUids([$uid])->setStatuses(['enqueued', 'processing'])
            )->getResults();

            if ($pending === []) {
                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Meilisearch did not finish indexing [{$uid}] within 30 seconds.");
    }
}
