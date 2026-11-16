<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Domain\Catalogue\Features\FeatureLabels;
use App\Domain\Catalogue\Urls\CategoryUrl;

/**
 * The "Shop by standard" chips, from config/homepage.php. Each links to the
 * safety-footwear listing filtered by one facet value.
 */
final class HomeStandardChips
{
    public function __construct(private readonly CategoryUrl $urls) {}

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
                'url' => (string) $this->urls->forHandle(HomeCategoryTiles::PARENT, [$facet => [$value]]),
                'facet' => $facet,
                'value' => $value,
            ];
        }

        return $chips;
    }
}
