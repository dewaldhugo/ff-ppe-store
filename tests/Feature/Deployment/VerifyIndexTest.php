<?php

declare(strict_types=1);

use App\Domain\Catalogue\Search\StorefrontProductIndexer;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Lunar\Models\Product;
use Lunar\Search\ProductIndexer;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Contracts\DocumentsResults;
use Meilisearch\Contracts\TasksQuery;
use Meilisearch\Contracts\TasksResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;

/**
 * A fake Meilisearch client and products index. Nothing here talks to
 * Meilisearch.
 *
 * @param  list<int>  $indexed  Product IDs that are in the index.
 * @param  array<int, array<string, mixed>>  $override  Fields of a document, by product ID.
 * @param  list<array<string, mixed>>  $failedTasks
 * @param  list<array<string, mixed>>  $taskQueries  Filled with the query array of every getTasks call.
 */
function fakeIndexClient(array $indexed, array $failedTasks = [], int $pending = 0, array $override = [], ?int $reportedCount = null, ?ApiException $statsError = null, array &$taskQueries = []): void
{
    $index = Mockery::mock(Indexes::class);

    if ($statsError !== null) {
        $index->shouldReceive('stats')->andThrow($statsError);
    } else {
        $index->shouldReceive('stats')->andReturn(['numberOfDocuments' => $reportedCount ?? count($indexed)]);
    }

    $index->shouldReceive('getDocuments')->andReturnUsing(function (DocumentsQuery $query) use ($indexed): DocumentsResults {
        $page = $query->toArray();

        return new DocumentsResults([
            'results' => array_map(fn (int $id): array => ['id' => (string) $id], array_slice($indexed, $page['offset'], $page['limit'])),
            'offset' => $page['offset'],
            'limit' => $page['limit'],
            'total' => count($indexed),
        ]);
    });

    // The document is what the indexer builds today, unless the test says otherwise.
    $index->shouldReceive('getDocument')->andReturnUsing(fn (string $id): array => [
        ...app(StorefrontProductIndexer::class)->toSearchableArray(Product::query()->withTrashed()->findOrFail((int) $id)),
        ...($override[(int) $id] ?? []),
    ]);

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('index')->andReturn($index);
    $client->shouldReceive('getTasks')->andReturnUsing(function (TasksQuery $query) use ($failedTasks, $pending, &$taskQueries): TasksResults {
        $array = $query->toArray();
        $taskQueries[] = $array;

        if (($array['statuses'] ?? '') === 'failed') {
            return new TasksResults(['results' => array_slice($failedTasks, 0, $array['limit'] ?? 20), 'total' => count($failedTasks)]);
        }

        return new TasksResults(['results' => [], 'total' => $pending]);
    });

    app()->instance(Client::class, $client);
}

/**
 * @return list<int>
 */
function indexTestProductIds(): array
{
    return Product::query()->withTrashed()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
}

/**
 * @return list<int> IDs of the new products.
 */
function indexTestAddProducts(int $count): array
{
    $template = Product::query()->firstOrFail();
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $ids[] = (int) tap($template->replicate())->save()->id;
    }

    return $ids;
}

it('passes when the index holds exactly the products in the database', function (): void {
    fakeIndexClient(indexTestProductIds());

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('OK: no product is missing from the index.')
        ->expectsOutputToContain('OK: no unexpected document in the index.')
        ->expectsOutputToContain('OK: no failed Meilisearch tasks.')
        ->expectsOutputToContain('match the database')
        ->assertSuccessful();
});

it('expects drafts and soft-deleted products too, because the indexer indexes them', function (): void {
    Product::query()->update(['status' => 'draft']);
    Product::query()->orderBy('id')->firstOrFail()->delete();

    fakeIndexClient(indexTestProductIds());

    $this->artisan('catalogue:verify-index')->assertSuccessful();
});

it('fails and names a soft-deleted product that is missing from the index', function (): void {
    $deleted = Product::query()->orderBy('id')->firstOrFail();
    $deleted->delete();

    fakeIndexClient(array_values(array_diff(indexTestProductIds(), [$deleted->id])));

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain("1 products are missing from the index. First 1 product IDs: {$deleted->id}")
        ->assertFailed();
});

it('lists at most 20 missing product ids and says how many there are', function (): void {
    $before = indexTestProductIds();
    $added = indexTestAddProducts(25);

    fakeIndexClient($before);

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('25 products are missing from the index. First 20 product IDs: '.implode(', ', array_slice($added, 0, 20)))
        ->doesntExpectOutputToContain((string) $added[20].',')
        ->assertFailed();
});

it('lists at most 20 documents that should not be in the index', function (): void {
    $extra = range(9000001, 9000025);

    fakeIndexClient([...indexTestProductIds(), ...$extra]);

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('25 documents in the index are not products that should be indexed. First 20 product IDs: '.implode(', ', array_slice($extra, 0, 20)))
        ->assertFailed();
});

it('follows the indexer\'s own shouldBeSearchable rule, not "published"', function (): void {
    // An indexer that only indexes published products.
    $publishedOnly = new class extends ProductIndexer
    {
        public function shouldBeSearchable(Model $model): bool
        {
            return $model->status === 'published';
        }
    };

    app()->instance($publishedOnly::class, $publishedOnly);
    config(['lunar.search.indexers.'.Product::class => $publishedOnly::class]);

    $published = Product::query()->where('status', 'published')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    Product::query()->whereKeyNot($published[0])->update(['status' => 'draft']);

    // Only the published product is expected, so the drafts count as extra.
    fakeIndexClient(indexTestProductIds());

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('documents in the index are not products that should be indexed')
        ->assertFailed();

    fakeIndexClient([$published[0]]);

    $this->artisan('catalogue:verify-index')->expectsOutputToContain('Expected 1 products')->assertSuccessful();
});

it('fails when Meilisearch reports a different document count from the listing', function (): void {
    fakeIndexClient(indexTestProductIds(), reportedCount: count(indexTestProductIds()) + 3);

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('Meilisearch reports')
        ->assertFailed();
});

it('reports the count and the first few messages of failed tasks, and fails', function (): void {
    $tasks = array_map(fn (int $uid): array => [
        'uid' => $uid,
        'type' => 'documentAdditionOrUpdate',
        'error' => ['message' => "Document {$uid} is malformed", 'code' => 'invalid_document'],
    ], range(1, 7));

    fakeIndexClient(indexTestProductIds(), failedTasks: $tasks);

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('7 failed Meilisearch tasks. First 5:')
        ->expectsOutputToContain('task 1 (documentAdditionOrUpdate): Document 1 is malformed [invalid_document]')
        ->expectsOutputToContain('task 5')
        ->doesntExpectOutputToContain('task 6')
        ->assertFailed();
});

it('only looks at failed tasks of the products index, and after --since when given', function (): void {
    $queries = [];
    fakeIndexClient(indexTestProductIds(), taskQueries: $queries);

    $this->artisan('catalogue:verify-index', ['--since' => '2026-11-01'])
        ->expectsOutputToContain('OK: no failed Meilisearch tasks enqueued after 2026-11-01.')
        ->assertSuccessful();

    $failed = collect($queries)->firstWhere('statuses', 'failed');

    expect($failed['indexUids'])->toBe((new Product)->searchableAs())
        ->and($failed['afterEnqueuedAt'])->toStartWith('2026-11-01');
});

it('rejects a --since that is not a date', function (): void {
    fakeIndexClient(indexTestProductIds());

    $this->artisan('catalogue:verify-index', ['--since' => 'not a date'])
        ->expectsOutputToContain('--since is not a date')
        ->assertFailed();
});

it('warns about tasks still processing without failing on that alone', function (): void {
    fakeIndexClient(indexTestProductIds(), pending: 12);

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('12 Meilisearch tasks are still enqueued or processing')
        ->assertSuccessful();
});

it('fails when the index does not exist', function (): void {
    fakeIndexClient([], statsError: new ApiException(new Response(404), ['message' => 'Index `products` not found.', 'code' => 'index_not_found']));

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when Meilisearch cannot be reached', function (): void {
    $index = Mockery::mock(Indexes::class);
    $index->shouldReceive('stats')->andThrow(new RuntimeException('Connection refused'));
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('index')->andReturn($index);
    app()->instance(Client::class, $client);

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('Could not reach Meilisearch: Connection refused')
        ->assertFailed();
});

it('finds a stale status and price in the sample', function (): void {
    $ids = indexTestProductIds();
    $stale = Product::query()->where('status', 'published')->firstOrFail();

    // The index still says draft, with a price that is no longer right.
    fakeIndexClient($ids, override: [$stale->id => ['status' => 'draft', 'price_min_ex_vat' => 1]]);

    $this->artisan('catalogue:verify-index')
        ->expectsOutputToContain('differences in a random sample')
        ->expectsOutputToContain("product {$stale->id} status: index \"draft\", database \"published\"")
        ->expectsOutputToContain("product {$stale->id} price_min_ex_vat: index 1, database")
        ->assertFailed();
});

it('never writes to Meilisearch or the database', function (): void {
    fakeIndexClient(indexTestProductIds());
    $before = Product::query()->withTrashed()->count();

    $this->artisan('catalogue:verify-index')->assertSuccessful();

    // The fake client has no write method, so any write would have failed the run.
    expect(Product::query()->withTrashed()->count())->toBe($before);
});
