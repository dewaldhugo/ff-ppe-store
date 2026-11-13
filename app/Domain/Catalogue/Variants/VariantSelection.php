<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Variants;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;

/**
 * The variant selection rules for the product page, free of any UI.
 *
 * - Add to cart needs a value for every option the product has.
 * - An option with a single value is selected automatically.
 * - A product with one variant and no options needs no selection.
 * - A value is unavailable when, together with the other current choices,
 *   it leads to no variant or to one without stock. Re-evaluated after
 *   every selection.
 * - The first in-stock colour is pre-selected. A size never is.
 *
 * Values are addressed by key: a slug for colours (`dark-red`, as in the
 * search index and the ?colour= query string), the plain name otherwise
 * (`9.5`).
 */
final class VariantSelection
{
    /** Customer wording for "Please select a ...". */
    private const array PROMPT_NAMES = [
        'colour' => 'colour',
        'shoe_size' => 'size',
        'garment_size' => 'size',
    ];

    /**
     * Option handle => [name => string, values => [key => [name, meta, position]]].
     *
     * @var array<string, array{name: string, values: array<string, array{name: string, meta: array<string, mixed>, position: float}>}>
     */
    private array $options = [];

    /**
     * One entry per variant: its stock and its value key for each option.
     *
     * @var list<array{variant: ProductVariant, stock: int, values: array<string, string>}>
     */
    private array $variants = [];

    /** @var array<string, string> Option handle => chosen value key. */
    private array $selected = [];

    /**
     * @param  Collection<int, ProductVariant>  $variants  With `values.option` loaded.
     * @param  array<string, ?string>  $requested  Choices to restore, e.g. from the query string.
     */
    public function __construct(Collection $variants, array $requested = [])
    {
        foreach ($variants as $variant) {
            $values = [];

            foreach ($variant->values as $value) {
                $handle = (string) $value->option->handle;
                $name = (string) $value->translate('name');
                $key = self::key($handle, $name);

                $this->options[$handle] ??= ['name' => (string) $value->option->translate('name'), 'values' => []];
                $this->options[$handle]['values'][$key] ??= [
                    'name' => $name,
                    'meta' => $value->meta?->toArray() ?? [],
                    'position' => (float) ($value->meta['sort'] ?? $value->position ?? 0),
                ];

                $values[$handle] = $key;
            }

            $this->variants[] = ['variant' => $variant, 'stock' => (int) $variant->stock, 'values' => $values];
        }

        foreach ($this->options as $handle => $option) {
            uasort($option['values'], fn (array $a, array $b): int => $a['position'] <=> $b['position']);
            $this->options[$handle]['values'] = $option['values'];
        }

        // Colour first, then the rest in the order they were found.
        if (isset($this->options['colour'])) {
            $this->options = ['colour' => $this->options['colour']] + $this->options;
        }

        $this->applyDefaults($requested);
    }

    public static function forProduct(Product $product, array $requested = []): self
    {
        $product->loadMissing('variants.values.option');

        return new self($product->variants, $requested);
    }

    public static function key(string $optionHandle, string $valueName): string
    {
        return $optionHandle === 'colour' ? Str::slug($valueName) : $valueName;
    }

    /**
     * Choose a value. Unknown options and values are ignored. Choosing a
     * value drops any other choice that no longer exists alongside it.
     */
    public function select(string $handle, ?string $key): self
    {
        if (! isset($this->options[$handle])) {
            return $this;
        }

        if ($key === null || $key === '' || ! isset($this->options[$handle]['values'][$key])) {
            if (! $this->isFixed($handle)) {
                unset($this->selected[$handle]);
            }

            return $this;
        }

        $this->selected[$handle] = $key;

        foreach (array_keys($this->selected) as $other) {
            if ($other !== $handle && ! $this->isFixed($other) && ! $this->combinationExists($other, $this->selected[$other])) {
                unset($this->selected[$other]);
            }
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function selected(): array
    {
        return $this->selected;
    }

    public function selectedKey(string $handle): ?string
    {
        return $this->selected[$handle] ?? null;
    }

    public function selectedName(string $handle): ?string
    {
        $key = $this->selectedKey($handle);

        return $key === null ? null : $this->options[$handle]['values'][$key]['name'];
    }

    /**
     * Options for display, in order.
     *
     * @return list<array{
     *     handle: string,
     *     name: string,
     *     prompt: string,
     *     fixed: bool,
     *     selected: ?string,
     *     values: list<array{key: string, name: string, meta: array<string, mixed>, selected: bool, available: bool}>
     * }>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->options as $handle => $option) {
            $values = [];

            foreach ($option['values'] as $key => $value) {
                $values[] = [
                    'key' => (string) $key,
                    'name' => $value['name'],
                    'meta' => $value['meta'],
                    'selected' => ($this->selected[$handle] ?? null) === (string) $key,
                    'available' => $this->isAvailable($handle, (string) $key),
                ];
            }

            $options[] = [
                'handle' => $handle,
                'name' => $option['name'],
                'prompt' => self::PROMPT_NAMES[$handle] ?? Str::lower($option['name']),
                'fixed' => $this->isFixed($handle),
                'selected' => $this->selected[$handle] ?? null,
                'values' => $values,
            ];
        }

        return $options;
    }

    /**
     * True when choosing this value, with the other current choices kept,
     * can still end at a variant that is in stock.
     */
    public function isAvailable(string $handle, string $key): bool
    {
        foreach ($this->variants as $entry) {
            if (($entry['values'][$handle] ?? null) !== $key || $entry['stock'] <= 0) {
                continue;
            }

            if ($this->matchesSelection($entry['values'], except: $handle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handles of options that still need a choice.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_diff(array_keys($this->options), array_keys($this->selected)));
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    /**
     * The message for the first option still needing a choice.
     */
    public function promptFor(string $handle): string
    {
        $name = self::PROMPT_NAMES[$handle] ?? Str::lower($this->options[$handle]['name'] ?? 'option');

        return "Please select a {$name}";
    }

    /**
     * The variant the current choices point at, once every option has one.
     */
    public function variant(): ?ProductVariant
    {
        if (! $this->isComplete()) {
            return null;
        }

        foreach ($this->variants as $entry) {
            if ($this->matchesSelection($entry['values'])) {
                return $entry['variant'];
            }
        }

        return null;
    }

    public function canAddToCart(): bool
    {
        $variant = $this->variant();

        return $variant !== null && $variant->stock > 0;
    }

    /**
     * Variants matching the choices made so far.
     *
     * @return list<ProductVariant>
     */
    public function matchingVariants(): array
    {
        $matching = [];

        foreach ($this->variants as $entry) {
            if ($this->matchesSelection($entry['values'])) {
                $matching[] = $entry['variant'];
            }
        }

        return $matching;
    }

    /**
     * @param  array<string, ?string>  $requested
     */
    private function applyDefaults(array $requested): void
    {
        // Single-value options are chosen for the customer.
        foreach ($this->options as $handle => $option) {
            if (count($option['values']) === 1) {
                $this->selected[$handle] = (string) array_key_first($option['values']);
            }
        }

        foreach ($requested as $handle => $key) {
            if (is_string($key) && isset($this->options[$handle]['values'][$key])) {
                $this->select($handle, $key);
            }
        }

        // First in-stock colour. Sizes are never chosen for the customer.
        if (isset($this->options['colour']) && ! isset($this->selected['colour'])) {
            foreach (array_keys($this->options['colour']['values']) as $key) {
                if ($this->isAvailable('colour', (string) $key)) {
                    $this->selected['colour'] = (string) $key;

                    break;
                }
            }
        }
    }

    private function isFixed(string $handle): bool
    {
        return count($this->options[$handle]['values'] ?? []) === 1;
    }

    private function combinationExists(string $handle, string $key): bool
    {
        foreach ($this->variants as $entry) {
            if (($entry['values'][$handle] ?? null) === $key && $this->matchesSelection($entry['values'], except: $handle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function matchesSelection(array $values, ?string $except = null): bool
    {
        foreach ($this->selected as $handle => $key) {
            if ($handle !== $except && ($values[$handle] ?? null) !== $key) {
                return false;
            }
        }

        return true;
    }
}
