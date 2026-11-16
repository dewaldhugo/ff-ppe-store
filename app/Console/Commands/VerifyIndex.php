<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogue\Search\StorefrontProductIndexer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Lunar\Models\Product;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Contracts\TasksQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Throwable;

/**
 * Read-only: compares the products index with the database. Run it after
 * every import, once the index has had time to catch up. It writes nothing.
 *
 * What should be in the index is decided by the rule Scout itself uses to
 * index everything (Product::makeAllSearchableQuery(), which includes
 * soft-deleted products while scout.soft_delete is true) and then each
 * product's own shouldBeSearchable(). It is not "published": drafts are
 * indexed too, because the admin panel searches the same index.
 */
final class VerifyIndex extends Command
{
    private const int SAMPLE = 20;

    private const int FAILED_TASKS_SHOWN = 5;

    protected $signature = 'catalogue:verify-index {--since= : Only count failed Meilisearch tasks enqueued after this date (for example 2026-11-01)}';

    protected $description = 'Compare the products search index with the database: missing and extra products, failed tasks, a content sample (read-only)';

    public function handle(Client $client): int
    {
        $since = null;

        if (filled($this->option('since'))) {
            try {
                $since = Carbon::parse((string) $this->option('since'));
            } catch (Throwable) {
                $this->error('--since is not a date: '.$this->option('since'));

                return self::FAILURE;
            }
        }

        $uid = (new Product)->searchableAs();

        try {
            $index = $client->index($uid);
            $documents = (int) $index->stats()['numberOfDocuments'];
        } catch (ApiException $exception) {
            $this->error($exception->httpStatus === 404
                ? "The index [{$uid}] does not exist. Run catalogue:sync-search-settings, then reindex."
                : "Meilisearch error for index [{$uid}]: {$exception->getMessage()}");

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Could not reach Meilisearch: '.$exception->getMessage());

            return self::FAILURE;
        }

        try {
            $problems = $this->check($client, $index, $uid, $documents, $since);
        } catch (Throwable $exception) {
            $this->error('Could not read from Meilisearch: '.$exception->getMessage());

            return self::FAILURE;
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(Client $client, Indexes $index, string $uid, int $documents, ?Carbon $since): int
    {
        $expected = $this->expectedIds();
        $indexed = $this->indexedIds($index);
        $missing = array_keys(array_diff_key($expected, $indexed));
        $extra = array_keys(array_diff_key($indexed, $expected));
        $problems = 0;

        $this->line(sprintf('Index [%s]: %d documents. Expected %d products (every product Scout indexes, drafts and soft-deleted included).', $uid, $documents, count($expected)));

        $problems += $this->report($missing, 'products are missing from the index', 'OK: no product is missing from the index.');
        $problems += $this->report($extra, 'documents in the index are not products that should be indexed', 'OK: no unexpected document in the index.');

        if ($documents !== count($indexed)) {
            $problems++;
            $this->error("Meilisearch reports {$documents} documents but listing them returned ".count($indexed).'. Run the check again.');
        }

        $problems += $this->failedTasks($client, $uid, $since);
        $problems += $this->sample($index, array_keys(array_intersect_key($expected, $indexed)));

        $pending = $client->getTasks((new TasksQuery)->setIndexUids([$uid])->setStatuses(['enqueued', 'processing'])->setLimit(1))->getTotal();

        if ($pending > 0) {
            $this->warn("{$pending} Meilisearch tasks are still enqueued or processing: differences above may be temporary. Run this again when they have finished.");
        }

        return $problems;
    }

    /**
     * @return array<int, true>
     */
    private function expectedIds(): array
    {
        $ids = [];

        Product::makeAllSearchableQuery()
            ->setEagerLoads([])
            ->chunkById(500, function ($products) use (&$ids): void {
                foreach ($products as $product) {
                    if ($product->shouldBeSearchable()) {
                        $ids[(int) $product->id] = true;
                    }
                }
            });

        return $ids;
    }

    /**
     * @return array<int, true>
     */
    private function indexedIds(Indexes $index): array
    {
        $ids = [];
        $offset = 0;

        do {
            $page = $index->getDocuments((new DocumentsQuery)->setFields(['id'])->setLimit(1000)->setOffset($offset));

            foreach ($page->getResults() as $document) {
                $ids[(int) $document['id']] = true;
            }

            $offset += 1000;
        } while ($offset < $page->getTotal());

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     */
    private function report(array $ids, string $problem, string $ok): int
    {
        if ($ids === []) {
            $this->info($ok);

            return 0;
        }

        sort($ids);
        $this->error(count($ids)." {$problem}. First ".min(count($ids), self::SAMPLE).' product IDs: '.implode(', ', array_slice($ids, 0, self::SAMPLE)));

        return 1;
    }

    private function failedTasks(Client $client, string $uid, ?Carbon $since): int
    {
        $query = (new TasksQuery)->setIndexUids([$uid])->setStatuses(['failed'])->setLimit(self::FAILED_TASKS_SHOWN);

        if ($since !== null) {
            $query->setAfterEnqueuedAt($since);
        }

        $tasks = $client->getTasks($query);
        $suffix = $since === null ? '' : ' enqueued after '.$since->toDateString();

        if ($tasks->getTotal() === 0) {
            $this->info("OK: no failed Meilisearch tasks{$suffix}.");

            return 0;
        }

        $this->error($tasks->getTotal()." failed Meilisearch tasks{$suffix}. First ".count($tasks->getResults()).':');

        foreach ($tasks->getResults() as $task) {
            $this->line(sprintf('  task %s (%s): %s [%s]', $task['uid'] ?? '?', $task['type'] ?? '?', $task['error']['message'] ?? 'no message', $task['error']['code'] ?? 'no code'));
        }

        return 1;
    }

    /**
     * Compares status and price of up to 20 random indexed products with
     * the document the indexer would build from the database today.
     *
     * @param  list<int>  $ids  Products that are both expected and indexed.
     */
    private function sample(Indexes $index, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $differences = [];

        foreach (Arr::random($ids, min(self::SAMPLE, count($ids))) as $id) {
            $product = Product::query()->withTrashed()->find($id);
            $document = $index->getDocument((string) $id);
            $built = app(StorefrontProductIndexer::class)->toSearchableArray($product);

            foreach (['status', 'price_min_ex_vat'] as $field) {
                if (($document[$field] ?? null) !== $built[$field]) {
                    $differences[] = sprintf('product %d %s: index %s, database %s', $id, $field, json_encode($document[$field] ?? null), json_encode($built[$field]));
                }
            }
        }

        if ($differences === []) {
            $this->info('OK: the status and ex VAT price of a random sample of '.min(self::SAMPLE, count($ids)).' indexed products match the database.');

            return 0;
        }

        $this->error(count($differences).' differences in a random sample of '.min(self::SAMPLE, count($ids)).' indexed products (the index is stale):');

        foreach ($differences as $difference) {
            $this->line("  {$difference}");
        }

        return 1;
    }
}
