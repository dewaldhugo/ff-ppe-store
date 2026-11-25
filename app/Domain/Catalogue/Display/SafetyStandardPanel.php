<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Display;

use App\Domain\Catalogue\Features\FeatureLabels;
use Lunar\Models\Product;

/**
 * The "Safety standard" block on a product page: the safety class (S3S), its feature codes (SR, SC, FO, CI, ESD) and the standards it
 * is certified to, with what each code means (FeatureLabels) as hover text. All from the product's own attributes (data contract,
 * Product attributes). Null when the product has none of them, as for most non-footwear.
 */
final class SafetyStandardPanel
{
    /** The feature codes in the data contract that are not shown as a short code (METAL_FREE is a phrase, not a code). */
    private const array NOT_A_CODE = ['METAL_FREE'];

    /**
     * @return ?array{class: ?string, codes: list<string>, standards: list<string>, meanings: array<string, string>}
     */
    public function for(Product $product): ?array
    {
        $class = trim((string) $product->attr('safety_class'));
        $features = $this->list($product->attr('features'));
        $standards = $this->list($product->attr('standards'));

        if ($class === '' && $features === [] && $standards === []) {
            return null;
        }

        $codes = array_values(array_filter($features, fn (string $code): bool => ! in_array($code, self::NOT_A_CODE, true)));
        $meanings = [];

        foreach ($codes as $code) {
            $label = FeatureLabels::label($code);
            $explanation = FeatureLabels::tooltip($code);

            $meanings[$code] = $explanation === null ? $label : "{$label}: {$explanation}";
        }

        return [
            'class' => $class === '' ? null : $class,
            'codes' => $codes,
            'standards' => $standards,
            'meanings' => $meanings,
        ];
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $item): string => trim((string) $item), is_array($value) ? $value : []),
            fn (string $item): bool => $item !== '',
        ));
    }
}
