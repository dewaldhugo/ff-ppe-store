<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Search\ListingQuery;
use App\Livewire\Catalogue\CategoryListing;
use Livewire\Livewire;
use Lunar\Models\Product;
use Tests\Support\SearchIndex;

beforeEach(function (): void {
    SearchIndex::use();
});

/** The id the router passes to the listing once it has resolved /shop/safety-footwear. */
function footwearId(): int
{
    return CatalogueKeys::collection('safety-footwear')->id;
}

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
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    expect(gridNames($html))->toEqualCanonicalizing(['LIGERO', 'SX310', 'FS9', 'FS65', 'FS30C'])
        ->and($html)->toContain('5 products');
});

it('returns 404 for an unknown category', function (): void {
    $this->get(route('category', 'not-a-category'))->assertNotFound();
});

it('filters Safety Footwear by S1P and SRC down to Ligero and FS65', function (): void {
    $html = $this->get(route('category', 'safety-footwear').'?safety_class[0]=S1P&features[0]=SRC')->assertOk()->getContent();

    expect(gridNames($html))->toEqualCanonicalizing(['LIGERO', 'FS65'])
        ->and($html)->toContain('2 products');
});

it('reads every filter from the query string so filtered URLs can be shared', function (): void {
    $html = $this->get(route('category', 'safety-footwear').'?brand[0]=Amblers+Safety&in_stock=1&sort=price-desc')->assertOk()->getContent();

    expect(gridNames($html))->toBe(['FS9', 'FS65']);

    Livewire::withQueryParams(['brand' => ['Centek'], 'sort' => 'newest', 'page' => 1])
        ->test(CategoryListing::class, ['collectionId' => footwearId()])
        ->assertSet('brand', ['Centek'])
        ->assertSet('sort', 'newest');
});

it('shows facet counts from the search index', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->getContent();

    expect(facetCounts($html, 'brand'))->toBe(['Amblers Safety' => 2, 'Centek' => 1, 'Maxguard' => 1, 'Safety Jogger' => 1])
        ->and(facetCounts($html, 'safety_class'))->toBe(['S1P' => 2, 'S3' => 1, 'SBP' => 1])
        ->and(facetCounts($html, 'gender'))->toBe(['Men' => 3, 'Unisex' => 2]);
});

it('keeps a facet\'s own counts when one of its values is selected', function (): void {
    $html = $this->get(route('category', 'safety-footwear').'?brand[0]=Centek')->getContent();

    expect(gridNames($html))->toBe(['FS30C'])
        ->and(facetCounts($html, 'brand'))->toBe(['Amblers Safety' => 2, 'Centek' => 1, 'Maxguard' => 1, 'Safety Jogger' => 1])
        ->and(facetCounts($html, 'gender'))->toBe(['Men' => 1]);
});

it('labels feature facets from the shared feature labels', function (): void {
    $this->get(route('category', 'safety-footwear'))
        ->assertSee('Slip resistant (SRC)')
        ->assertSee('ESD, electrostatic dissipative')
        ->assertSee('Metal free');
});

it('shows an out of stock badge and hides the product when in stock only is ticked', function (): void {
    $all = $this->get(route('category', 'safety-footwear'))->getContent();

    expect(gridNames($all))->toContain('SX310')
        ->and($all)->toMatch('#Out of stock</span>.*?SX310#s');

    $inStock = Livewire::test(CategoryListing::class, ['collectionId' => footwearId()])
        ->set('in_stock', true)
        ->html();

    expect(gridNames($inStock))->toEqualCanonicalizing(['LIGERO', 'FS9', 'FS65', 'FS30C']);
});

it('filters by colour and size together as a combination', function (): void {
    $component = Livewire::test(CategoryListing::class, ['collectionId' => footwearId()])
        ->set('colour', ['black'])
        ->set('size', ['10']);

    expect(gridNames($component->html()))->not->toContain('LIGERO');

    $component->set('colour', ['navy']);

    expect(gridNames($component->html()))->toBe(['LIGERO']);
});

it('filters by price ex VAT', function (): void {
    // LIGERO is 68.90 ex VAT and 82.68 inc VAT.
    expect(gridNames($this->get(route('category', 'safety-footwear').'?price_min=65&price_max=70')->getContent()))->toBe(['LIGERO'])
        ->and(gridNames($this->get(route('category', 'safety-footwear').'?price_min=80&price_max=85')->getContent()))->toBe([]);
});

it('sorts by price and by newest', function (): void {
    $sorted = fn (string $sort): array => gridNames($this->get(route('category', 'safety-footwear').'?sort='.$sort)->getContent());

    // Ex VAT, FS65 and FS30C are both 40.48, so their order is a tie.
    expect($sorted('price-asc')[0])->toBeIn(['FS30C', 'FS65'])
        ->and(array_slice($sorted('price-desc'), 0, 3))->toBe(['LIGERO', 'SX310', 'FS9'])
        ->and(array_slice($sorted('price-desc'), 3))->toEqualCanonicalizing(['FS65', 'FS30C'])
        ->and($sorted('newest'))->toHaveCount(5);
});

it('shows prices ex VAT with the suffix', function (): void {
    $this->get(route('category', 'safety-footwear'))
        ->assertSee('£68.90')
        ->assertSee('£52.48')
        ->assertSee('ex VAT')
        ->assertDontSee('£82.68')
        ->assertDontSee('inc VAT');
});

it('goes back to page one when a filter changes and clears filters', function (): void {
    Livewire::test(CategoryListing::class, ['collectionId' => footwearId()])
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

    Livewire::test(CategoryListing::class, ['collectionId' => footwearId()])
        ->assertViewHas('result', fn ($result): bool => $result->perPage === 24 && $result->lastPage === 1);
});

it('searches across the catalogue', function (): void {
    $html = $this->get(route('search', ['q' => 'slipper']))->assertOk()->getContent();

    expect(gridNames($html))->toEqualCanonicalizing(['Eva', 'Exeter Slipper'])
        ->and($html)->toContain('Search results for');
});

it('points every filtered or sorted listing at the unfiltered category URL', function (string $query): void {
    $canonical = '<link rel="canonical" href="'.route('category', 'safety-footwear').'">';

    $this->get(route('category', 'safety-footwear').$query)->assertOk()->assertSee($canonical, false);
})->with(['', '?brand[0]=Centek', '?sort=price-asc', '?brand[0]=Centek&sort=price-desc&in_stock=1', '?utm_source=x']);

it('gives a paginated listing a self-referencing canonical', function (): void {
    $page2 = route('category', 'safety-footwear').'?page=2';

    $this->get($page2)->assertOk()->assertSee('<link rel="canonical" href="'.e($page2).'">', false);

    // Paging combined with a filter goes back to the unfiltered URL.
    $this->get($page2.'&brand[0]=Centek')->assertOk()
        ->assertSee('<link rel="canonical" href="'.route('category', 'safety-footwear').'">', false);
});

it('keeps the search canonical with its query', function (): void {
    $this->get(route('search', ['q' => 'boots']))
        ->assertSee('<link rel="canonical" href="'.e(route('search', ['q' => 'boots'])).'">', false);
});

it('shows one non-interactive colour swatch per colour under each product', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match_all('#<li wire:key="product-(\d+)".*?</li>\s*(?=<li wire:key|</ul>)#s', $html, $cards, PREG_SET_ORDER);

    expect($cards)->not->toBeEmpty();

    foreach ($cards as [$card, $id]) {
        $expected = Product::query()
            ->with('variants.values.option')
            ->findOrFail((int) $id)
            ->variants
            ->map(fn ($variant) => $variant->values->first(fn ($value) => $value->option->handle === 'colour')?->translate('name'))
            ->filter()
            ->unique()
            ->count();

        expect(preg_match_all('/\bdata-swatch(?![\w-])/', $card))->toBe($expected);

        preg_match('#<p[^>]*data-swatches.*?</p>#s', $card, $swatches);

        // Not clickable, and nothing marks a colour as selected.
        expect($swatches[0] ?? '')->not->toMatch('#<(a|button|input)\b#')
            ->and($swatches[0] ?? '')->not->toContain('aria-pressed')
            ->and($swatches[0] ?? '')->not->toContain('aria-current')
            ->and($swatches[0] ?? '')->not->toContain('ring');
    }
});
