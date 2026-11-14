<?php

declare(strict_types=1);

use App\Domain\Catalogue\Search\ListingQuery;
use App\Domain\Pricing\PriceDisplay;
use App\Domain\Pricing\VatDisplayMode;
use App\Livewire\Catalogue\CategoryListing;
use Livewire\Livewire;
use Tests\Support\SearchIndex;

beforeEach(function (): void {
    SearchIndex::use();
});

/**
 * Product names in the grid, in order.
 *
 * @return list<string>
 */
function gridNames(string $html): array
{
    preg_match('#<ul[^>]*data-testid="product-grid".*?</ul>#s', $html, $grid);
    preg_match_all('#<h3[^>]*>\s*<a[^>]*>\s*(.*?)\s*</a>#s', $grid[0] ?? '', $matches);

    return array_map(trim(...), $matches[1]);
}

/**
 * Counts shown next to the values of one facet (desktop sidebar copy).
 *
 * @return array<string, int>
 */
function facetCounts(string $html, string $facet): array
{
    preg_match_all('#<fieldset data-facet="'.$facet.'">(.*?)</fieldset>#s', $html, $fieldsets);
    preg_match_all('#value="([^"]*)".*?data-count>\((\d+)\)#s', end($fieldsets[1]) ?: '', $matches);

    return array_combine($matches[1], array_map(intval(...), $matches[2]));
}

it('lists the collection and its descendants', function (): void {
    $html = $this->get('/c/safety-footwear')->assertOk()->getContent();

    expect(gridNames($html))->toEqualCanonicalizing(['LIGERO', 'SX310', 'FS9', 'FS65', 'FS30C'])
        ->and($html)->toContain('5 products');
});

it('returns 404 for an unknown category', function (): void {
    $this->get('/c/not-a-category')->assertNotFound();
});

it('filters Safety Footwear by S1P and SRC down to Ligero and FS65', function (): void {
    $html = $this->get('/c/safety-footwear?safety_class[0]=S1P&features[0]=SRC')->assertOk()->getContent();

    expect(gridNames($html))->toEqualCanonicalizing(['LIGERO', 'FS65'])
        ->and($html)->toContain('2 products');
});

it('reads every filter from the query string so filtered URLs can be shared', function (): void {
    $html = $this->get('/c/safety-footwear?brand[0]=Amblers+Safety&in_stock=1&sort=price-desc')->assertOk()->getContent();

    expect(gridNames($html))->toBe(['FS9', 'FS65']);

    Livewire::withQueryParams(['brand' => ['Centek'], 'sort' => 'newest', 'page' => 1])
        ->test(CategoryListing::class, ['slug' => 'safety-footwear'])
        ->assertSet('brand', ['Centek'])
        ->assertSet('sort', 'newest');
});

it('shows facet counts from the search index', function (): void {
    $html = $this->get('/c/safety-footwear')->getContent();

    expect(facetCounts($html, 'brand'))->toBe(['Amblers Safety' => 2, 'Centek' => 1, 'Maxguard' => 1, 'Safety Jogger' => 1])
        ->and(facetCounts($html, 'safety_class'))->toBe(['S1P' => 2, 'S3' => 1, 'SBP' => 1])
        ->and(facetCounts($html, 'gender'))->toBe(['Men' => 3, 'Unisex' => 2]);
});

it('keeps a facet\'s own counts when one of its values is selected', function (): void {
    $html = $this->get('/c/safety-footwear?brand[0]=Centek')->getContent();

    expect(gridNames($html))->toBe(['FS30C'])
        ->and(facetCounts($html, 'brand'))->toBe(['Amblers Safety' => 2, 'Centek' => 1, 'Maxguard' => 1, 'Safety Jogger' => 1])
        ->and(facetCounts($html, 'gender'))->toBe(['Men' => 1]);
});

it('labels feature facets from the shared feature labels', function (): void {
    $this->get('/c/safety-footwear')
        ->assertSee('Slip resistant (SRC)')
        ->assertSee('ESD, electrostatic dissipative')
        ->assertSee('Metal free');
});

it('shows an out of stock badge and hides the product when in stock only is ticked', function (): void {
    $all = $this->get('/c/safety-footwear')->getContent();

    expect(gridNames($all))->toContain('SX310')
        ->and($all)->toMatch('#Out of stock</span>.*?SX310#s');

    $inStock = Livewire::test(CategoryListing::class, ['slug' => 'safety-footwear'])
        ->set('in_stock', true)
        ->html();

    expect(gridNames($inStock))->toEqualCanonicalizing(['LIGERO', 'FS9', 'FS65', 'FS30C']);
});

it('filters by colour and size together as a combination', function (): void {
    $component = Livewire::test(CategoryListing::class, ['slug' => 'safety-footwear'])
        ->set('colour', ['black'])
        ->set('size', ['10']);

    expect(gridNames($component->html()))->not->toContain('LIGERO');

    $component->set('colour', ['navy']);

    expect(gridNames($component->html()))->toBe(['LIGERO']);
});

it('filters by price in the visitor\'s VAT mode', function (): void {
    $range = '/c/safety-footwear?price_min=65&price_max=70';

    expect(gridNames($this->get($range)->getContent()))->toBe([]);

    app(PriceDisplay::class)->setMode(VatDisplayMode::ExVat);

    expect(gridNames($this->get($range)->getContent()))->toBe(['LIGERO']);
});

it('sorts by price and by newest', function (): void {
    $sorted = fn (string $sort): array => gridNames($this->get('/c/safety-footwear?sort='.$sort)->getContent());

    expect($sorted('price-asc')[0])->toBe('FS30C')
        ->and($sorted('price-desc'))->toBe(['LIGERO', 'SX310', 'FS9', 'FS65', 'FS30C'])
        ->and($sorted('newest'))->toHaveCount(5);
});

it('shows prices in the visitor\'s VAT mode', function (): void {
    $this->get('/c/safety-footwear')->assertSee('£82.68')->assertSee('£52.48');

    app(PriceDisplay::class)->setMode(VatDisplayMode::ExVat);

    $this->get('/c/safety-footwear')->assertSee('£68.90')->assertSee('£52.48')->assertDontSee('£82.68');
});

it('goes back to page one when a filter changes and clears filters', function (): void {
    Livewire::test(CategoryListing::class, ['slug' => 'safety-footwear'])
        ->set('page', 3)
        ->set('brand', ['Centek'])
        ->assertSet('page', 1)
        ->set('in_stock', true)
        ->call('clearFilters')
        ->assertSet('brand', [])
        ->assertSet('in_stock', false);
});

it('paginates 24 products per page', function (): void {
    expect(ListingQuery::PER_PAGE)->toBe(24);

    Livewire::test(CategoryListing::class, ['slug' => 'safety-footwear'])
        ->assertViewHas('result', fn ($result): bool => $result->perPage === 24 && $result->lastPage === 1);
});

it('searches across the catalogue', function (): void {
    $html = $this->get('/search?q=slipper')->assertOk()->getContent();

    expect(gridNames($html))->toEqualCanonicalizing(['Eva', 'Exeter Slipper'])
        ->and($html)->toContain('Search results for');
});

it('has a canonical URL that includes the active filters', function (): void {
    $this->get('/c/safety-footwear')
        ->assertSee('<link rel="canonical" href="'.route('category', 'safety-footwear').'">', false);

    $html = $this->get('/c/safety-footwear?brand[0]=Centek')->getContent();

    expect($html)->toContain('rel="canonical" href="'.e(route('category', ['slug' => 'safety-footwear', 'brand' => ['Centek']])).'"');
});
