<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Options;

use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;

/**
 * The single lookup for values of the shared product options in the data
 * contract (`colour`, `shoe_size`, `garment_size`).
 *
 * Values are shared across products, so a value is looked up by its option
 * and English name and created only when missing. The fixture seeder and the
 * ingestion module both go through this class.
 */
final class SharedOptionValues
{
    /** @var array<string, ProductOption> */
    private array $options = [];

    /** @var array<string, ProductOptionValue> */
    private array $values = [];

    public function option(string $handle): ProductOption
    {
        return $this->options[$handle] ??= ProductOption::query()->where('handle', $handle)->sole();
    }

    /**
     * Find or create a value. A colour first seen without a hex picks one
     * up when a later caller supplies it. Existing values are otherwise
     * never changed.
     *
     * @param  array<string, mixed>  $meta
     */
    public function value(string $option, string $name, array $meta = [], int $position = 0): ProductOptionValue
    {
        $cacheKey = $option.':'.$name;
        $optionId = $this->option($option)->id;

        $value = $this->values[$cacheKey] ??= ProductOptionValue::query()
            ->where('product_option_id', $optionId)
            ->where('name->en', $name)
            ->first()
            ?? ProductOptionValue::query()->create([
                'product_option_id' => $optionId,
                'name' => ['en' => $name],
                'position' => $position,
                'meta' => $meta,
            ]);

        // A size's sort is canonical (see ShoeSizes and GarmentSizes), so a
        // value created before the canonical scale existed is brought up to
        // date when it is used.
        if (in_array($option, ['shoe_size', 'garment_size'], true) && isset($meta['sort']) && (float) ($value->meta['sort'] ?? -1) !== (float) $meta['sort']) {
            $value->update(['meta' => [...($value->meta?->toArray() ?? []), 'sort' => $meta['sort']]]);
        }

        if ($option === 'colour' && filled($meta['hex'] ?? null) && blank($value->meta['hex'] ?? null)) {
            $value->update(['meta' => [...($value->meta?->toArray() ?? []), 'hex' => $meta['hex']]]);
        }

        return $value;
    }

    /**
     * Forget everything memoised. Needed when the database is rolled back
     * underneath a long-lived instance, as in tests.
     */
    public function flush(): void
    {
        $this->options = [];
        $this->values = [];
    }
}
