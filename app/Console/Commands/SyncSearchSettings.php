<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogue\Search\IndexSettings;
use Illuminate\Console\Command;
use Lunar\Models\Product;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;

/**
 * Applies the versioned product index settings to Meilisearch. Run on every
 * deploy. It does nothing when the live settings already match.
 */
final class SyncSearchSettings extends Command
{
    protected $signature = 'catalogue:sync-search-settings {--force : Apply the settings even when they already match}';

    protected $description = 'Apply the versioned product search index settings to Meilisearch';

    public function handle(Client $client): int
    {
        $product = new Product;
        $uid = $product->searchableAs();

        try {
            $client->getIndex($uid);
        } catch (ApiException $exception) {
            if ($exception->httpStatus !== 404) {
                throw $exception;
            }

            $this->components->info("Creating index [{$uid}].");
            $client->waitForTask($client->createIndex($uid, ['primaryKey' => 'id'])['taskUid'], 30000);
        }

        $index = $client->index($uid);

        $wanted = [
            'searchableAttributes' => IndexSettings::searchable(),
            'filterableAttributes' => $product->getFilterableAttributes(),
            'sortableAttributes' => $product->getSortableAttributes(),
        ];

        $live = $index->getSettings();

        $matches = ($live['searchableAttributes'] ?? []) === $wanted['searchableAttributes']
            && $this->sameSet($live['filterableAttributes'] ?? [], $wanted['filterableAttributes'])
            && $this->sameSet($live['sortableAttributes'] ?? [], $wanted['sortableAttributes']);

        if ($matches && ! $this->option('force')) {
            $this->components->info(sprintf('Index [%s] already has settings version %d.', $uid, IndexSettings::VERSION));

            return self::SUCCESS;
        }

        $task = $client->waitForTask($index->updateSettings($wanted)['taskUid'], 120000);

        if (($task['status'] ?? null) !== 'succeeded') {
            $this->components->error('Meilisearch rejected the settings: '.json_encode($task['error'] ?? $task));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Applied settings version %d to index [%s].', IndexSettings::VERSION, $uid));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $a
     * @param  array<int, mixed>  $b
     */
    private function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
