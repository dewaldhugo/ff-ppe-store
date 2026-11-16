<?php

declare(strict_types=1);

use App\Domain\Catalogue\Home\HomeStandardChips;
use Tests\Support\SearchIndex;

beforeEach(function (): void {
    SearchIndex::use();
});

/** Product names in the listing grid. */
function chipGridNames(string $html): array
{
    preg_match('#<ul[^>]*data-testid="product-grid".*?</ul>#s', $html, $grid);
    preg_match_all('#<h3[^>]*>\s*<a[^>]*>\s*(.*?)\s*</a>#s', $grid[0] ?? '', $matches);

    return array_map(trim(...), $matches[1]);
}

/** The chips component is not on the home page any more: render it on its own. */
function standardsSection(string $html = ''): string
{
    $html = (string) test()->blade('<x-home.standard-chips />');

    preg_match('#<section[^>]*data-home-standards.*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

it('shows the five standard chips with their labels', function (): void {
    $section = standardsSection();

    preg_match_all('#<a\s+href="[^"]*"[^>]*data-standard-chip\s*>(.*?)</a>#s', $section, $labels);

    expect($labels[1])->toBe(['S1P', 'S3', 'ESD', 'Metal free', 'Waterproof'])
        ->and($section)->toMatch('#<h2[^>]*id="home-standards"[^>]*>Shop by standard</h2>#');
});

it('builds chip links from route() with the facet in the query string', function (): void {
    $section = standardsSection();

    $expected = [
        route('category', ['path' => 'safety-footwear', 'safety_class' => ['S1P']]),
        route('category', ['path' => 'safety-footwear', 'safety_class' => ['S3']]),
        route('category', ['path' => 'safety-footwear', 'features' => ['ESD']]),
        route('category', ['path' => 'safety-footwear', 'features' => ['METAL_FREE']]),
        route('category', ['path' => 'safety-footwear', 'features' => ['WR']]),
    ];

    foreach ($expected as $url) {
        expect($section)->toContain('href="'.e($url).'"');
    }
});

it('reads its chips from config/homepage.php', function (): void {
    config()->set('homepage.standards', [
        ['facet' => 'features', 'value' => 'HRO', 'label' => 'Heat resistant'],
    ]);

    $section = standardsSection();

    expect($section)->toContain('>Heat resistant</a>')
        ->and($section)->not->toContain('>S1P<')
        ->and(substr_count($section, 'data-standard-chip'))->toBe(1);
});

it('hides the section when no chips are configured', function (): void {
    config()->set('homepage.standards', []);

    expect(standardsSection())->toBe('');
});

it('returns products for every chip target', function (): void {
    $chips = app(HomeStandardChips::class)->chips();

    expect($chips)->toHaveCount(5);

    foreach ($chips as $chip) {
        $names = chipGridNames($this->get($chip['url'])->assertOk()->getContent());

        expect($names)->not->toBeEmpty("Chip {$chip['label']} ({$chip['url']}) returns no products");
    }
});

it('returns the expected fixture products for the S1P and Waterproof chips', function (): void {
    $names = fn (string $facet, string $value): array => chipGridNames(
        $this->get(route('category', ['path' => 'safety-footwear', $facet => [$value]]))->getContent(),
    );

    expect($names('safety_class', 'S1P'))->toContain('LIGERO')
        ->and($names('features', 'WR'))->toContain('SX310');
});
