<?php

declare(strict_types=1);

namespace App\Domain\Compare;

use App\Domain\Catalogue\Options\ProductSwatches;
use App\Domain\Pricing\PriceDisplay;
use Illuminate\Support\Collection;
use Lunar\Models\Product;

/**
 * The rows of the compare page: one row per fact, one cell per product, from the same product attributes the product page shows.
 * A row nobody has a value for is left out. `same` marks a row where every product has the same value, so the page can hide it
 * when asked to show the differences only. The price row is there only for a visitor who may see prices.
 */
final class CompareTable
{
    /** Attribute handle to row label, in the order of the product page's specification. */
    private const array ATTRIBUTES = [
        'safety_class' => 'Safety class',
        'standards' => 'Standards',
        'features' => 'Features',
        'toecap' => 'Toecap',
        'midsole' => 'Midsole',
        'outsole' => 'Outsole',
        'upper' => 'Upper',
        'lining' => 'Lining',
        'cut' => 'Cut',
        'fastening' => 'Fastening',
    ];

    public function __construct(private readonly PriceDisplay $prices) {}

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{label: string, cells: list<?string>, same: bool}>
     */
    public function rows(Collection $products): array
    {
        $rows = [
            $this->row('Brand', $products->map(fn (Product $p): ?string => $p->brand?->name)->all()),
        ];

        foreach (self::ATTRIBUTES as $handle => $label) {
            $rows[] = $this->row($label, $products->map(fn (Product $p): ?string => $this->text($p->attr($handle)))->all());
        }

        $rows[] = $this->row('Colours', $products->map(fn (Product $p): ?string => $this->text(array_column(ProductSwatches::for($p), 'name')))->all());
        $rows[] = $this->row('Availability', $products->map(fn (Product $p): string => $p->variants->sum('stock') > 0 ? 'In stock' : 'Out of stock')->all());

        if ($this->prices->visible()) {
            $rows[] = $this->row('Price', $products->map(function (Product $p): ?string {
                $price = $this->prices->fromPrice($p);

                if ($price === null) {
                    return null;
                }

                $range = $p->variants->flatMap->prices->pluck('price.value')->unique()->count() > 1;

                return trim(($range ? 'From ' : '').$price->formatted().' '.$price->suffix());
            })->all());
        }

        return array_values(array_filter($rows, fn (array $row): bool => array_filter($row['cells'], filled(...)) !== []));
    }

    /**
     * @param  list<?string>  $cells
     * @return array{label: string, cells: list<?string>, same: bool}
     */
    private function row(string $label, array $cells): array
    {
        $cells = array_values($cells);

        return ['label' => $label, 'cells' => $cells, 'same' => count($cells) > 1 && count(array_unique($cells)) === 1];
    }

    private function text(mixed $value): ?string
    {
        $items = array_values(array_filter(is_array($value) ? $value : [$value], filled(...)));

        return $items === [] ? null : implode(', ', array_map(fn (mixed $item): string => trim((string) $item), $items));
    }
}
