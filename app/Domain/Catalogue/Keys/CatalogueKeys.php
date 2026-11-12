<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Keys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Lunar\Models\Collection;
use Lunar\Models\ProductType;
use Lunar\Models\TaxClass;
use Lunar\Models\TaxZone;

/**
 * Resolves the handles used in the data contract to Lunar records.
 *
 * The storefront, the fixture seeder and the ingestion module all go through
 * this class. Never look these records up by name: names are editable in the
 * admin panel.
 */
final class CatalogueKeys
{
    private const string CACHE_KEY = 'catalogue_keys.map';

    /** @var array<string, Model> */
    private array $resolved = [];

    public static function taxClass(string $handle): TaxClass
    {
        return self::instance()->resolve(KeyType::TaxClass, $handle);
    }

    public static function productType(string $handle): ProductType
    {
        return self::instance()->resolve(KeyType::ProductType, $handle);
    }

    public static function collection(string $handle): Collection
    {
        return self::instance()->resolve(KeyType::Collection, $handle);
    }

    public static function taxZone(string $handle): TaxZone
    {
        return self::instance()->resolve(KeyType::TaxZone, $handle);
    }

    /**
     * The handle registered for a record, or null when it has none.
     */
    public static function handleFor(KeyType $type, Model $model): ?string
    {
        foreach (self::instance()->map()[$type->value] ?? [] as $handle => [$modelType, $modelId]) {
            if ($modelType === $model->getMorphClass() && $modelId === (int) $model->getKey()) {
                return (string) $handle;
            }
        }

        return null;
    }

    /**
     * Forget everything cached. Called by the foundation seeder.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::instance()->resolved = [];
    }

    /**
     * Find the record registered under a handle without throwing. Returns
     * null when the handle is not registered or its record has been deleted.
     * Reads the database directly, so it is safe to use while seeding.
     */
    public function find(KeyType $type, string $handle): ?Model
    {
        $key = CatalogueKey::query()
            ->where('key_type', $type->value)
            ->where('handle', $handle)
            ->first();

        return $key === null ? null : $this->load($key->model_type, $key->model_id);
    }

    /**
     * Point a handle at a record, creating or repairing the registry row.
     */
    public function register(KeyType $type, string $handle, Model $model): void
    {
        CatalogueKey::query()->updateOrCreate(
            ['key_type' => $type->value, 'handle' => $handle],
            ['model_type' => $model->getMorphClass(), 'model_id' => $model->getKey()],
        );

        self::flush();
    }

    /**
     * @template TModel of Model
     *
     * @return TModel
     */
    public function resolve(KeyType $type, string $handle): Model
    {
        $memoKey = $type->value.':'.$handle;

        if (isset($this->resolved[$memoKey])) {
            return $this->resolved[$memoKey];
        }

        $entry = $this->map()[$type->value][$handle] ?? null;

        if ($entry === null) {
            throw UnknownCatalogueKey::notRegistered($type, $handle);
        }

        $model = $this->load($entry[0], $entry[1]);

        if ($model === null) {
            throw UnknownCatalogueKey::recordMissing($type, $handle);
        }

        return $this->resolved[$memoKey] = $model;
    }

    private static function instance(): self
    {
        return app(self::class);
    }

    /**
     * @return array<string, array<string, array{0: string, 1: int}>>
     */
    private function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $map = [];

            foreach (CatalogueKey::query()->get() as $key) {
                $map[$key->key_type->value][$key->handle] = [$key->model_type, (int) $key->model_id];
            }

            return $map;
        });
    }

    private function load(string $modelType, int $modelId): ?Model
    {
        /** @var class-string<Model> $class */
        $class = Relation::getMorphedModel($modelType) ?? $modelType;

        return $class::query()->find($modelId);
    }
}
