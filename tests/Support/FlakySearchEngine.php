<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Lunar\Models\Product;
use RuntimeException;

/**
 * A Scout engine for tests: records every batch of products it is asked to
 * index (the database transaction level at the time, too) and throws on the
 * batches the test names, like Meilisearch being down.
 */
final class FlakySearchEngine extends NullEngine
{
    /** @var list<list<int>> Product IDs of every batch, failed ones included. */
    public array $batches = [];

    /** @var list<int> DB::transactionLevel() when each batch arrived. */
    public array $levels = [];

    /**
     * @param  list<int>  $failBatches  1-based positions of the batches that throw.
     * @param  bool  $failAll  Every batch throws.
     */
    public function __construct(public array $failBatches = [], public bool $failAll = false) {}

    /**
     * Installs this engine as Scout's driver for the rest of the test.
     */
    public static function install(array $failBatches = [], bool $failAll = false): self
    {
        $engine = new self($failBatches, $failAll);
        $name = 'flaky-'.spl_object_id($engine);

        app(EngineManager::class)->extend($name, fn (): FlakySearchEngine => $engine);
        config(['scout.driver' => $name]);

        return $engine;
    }

    public function update($models): void
    {
        // Brands go through the same engine; only products are counted.
        if (! $models->first() instanceof Product) {
            return;
        }

        $this->batches[] = $models->modelKeys();
        $this->levels[] = DB::transactionLevel();

        if ($this->failAll || in_array(count($this->batches), $this->failBatches, true)) {
            throw new RuntimeException('Meilisearch is down');
        }
    }
}
