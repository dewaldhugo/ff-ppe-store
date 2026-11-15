<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Domain\Catalogue\Features\FeatureLabels;

/**
 * The "Shop by standard" chips, from config/homepage.php. Each links to the
 * safety-footwear listing filtered by one facet value.
 */
final class HomeStandardChips
{
    /**
     * @return list<array{label: string, url: string, facet: string, value: string}>
     */
    public function chips(): array
    {
        $chips = [];

        foreach ((array) config('homepage.standards', []) as $chip) {
            $facet = (string) $chip['facet'];
            $value = (string) $chip['value'];

            $label = $chip['label']
                ?? ($facet === 'features' ? FeatureLabels::label($value) : $value);

            $chips[] = [
                'label' => (string) $label,
                'url' => route('category', ['slug' => HomeCategoryTiles::PARENT, $facet => [$value]]),
                'facet' => $facet,
                'value' => $value,
            ];
        }

        return $chips;
    }
}
