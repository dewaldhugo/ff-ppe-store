<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Search\ListingQuery;
use App\Livewire\Catalogue\CategoryListing;
use App\Models\User;
use Livewire\Livewire;
use Lunar\Models\Product;
use Tests\Support\SearchIndex;

beforeEach(function (): void {
    SearchIndex::use();

    // Only signed-in customers see prices or can filter and sort by them
    // (tests/Feature/Storefront/PriceGatingTest.php covers guests).
    $this->actingAs(User::factory()->create(), 'web');
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

    // Classic (occupational-footwear) is now a descendant of safety-footwear.
    expect(gridNames($html))->toEqualCanonicalizing(['Classic', 'LIGERO', 'SX310', 'FS9', 'FS65', 'FS30C'])
        ->and($html)->toContain('6 products');
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

    expect(facetCounts($html, 'brand'))->toBe(['Amblers Safety' => 2, 'Centek' => 1, 'Magnum' => 1, 'Maxguard' => 1, 'Safety Jogger' => 1])
        ->and(facetCounts($html, 'safety_class'))->toBe(['OB' => 1, 'S1P' => 2, 'S3' => 1, 'SBP' => 1])
        ->and(facetCounts($html, 'gender'))->toBe(['Men' => 4, 'Unisex' => 2]);
});

it('keeps a facet\'s own counts when one of its values is selected', function (): void {
    $html = $this->get(route('category', 'safety-footwear').'?brand[0]=Centek')->getContent();

    expect(gridNames($html))->toBe(['FS30C'])
        ->and(facetCounts($html, 'brand'))->toBe(['Amblers Safety' => 2, 'Centek' => 1, 'Magnum' => 1, 'Maxguard' => 1, 'Safety Jogger' => 1])
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

    expect(gridNames($inStock))->toEqualCanonicalizing(['Classic', 'LIGERO', 'FS9', 'FS65', 'FS30C']);
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

    // Ex VAT, FS65 and FS30C are both 40.48, so their order is a tie. Classic
    // (87.74, occupational-footwear) is the highest-priced descendant.
    expect($sorted('price-asc')[0])->toBeIn(['FS30C', 'FS65'])
        ->and(array_slice($sorted('price-desc'), 0, 3))->toBe(['Classic', 'LIGERO', 'SX310'])
        ->and(array_slice($sorted('price-desc'), 3))->toEqualCanonicalizing(['FS9', 'FS65', 'FS30C'])
        ->and($sorted('newest'))->toHaveCount(6);
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

it('paginates 40 products per page: 10 rows of the 4 cards of a wide screen', function (): void {
    expect(ListingQuery::PER_PAGE)->toBe(40)
        // Ten rows of four on a wide screen, and whole rows of two on a phone.
        ->and(ListingQuery::PER_PAGE % 4)->toBe(0)->and(intdiv(ListingQuery::PER_PAGE, 4))->toBe(10)
        ->and(ListingQuery::PER_PAGE % 2)->toBe(0);

    Livewire::test(CategoryListing::class, ['collectionId' => footwearId()])
        ->assertViewHas('result', fn ($result): bool => $result->perPage === 40 && $result->lastPage === 1);
});

it('gives the product grid 2 columns, then 3, then 4, then 5 at the largest breakpoint', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match('#<ul[^>]*data-testid="product-grid"[^>]*>#', $html, $grid);

    expect($grid[0])->toContain('grid-cols-2')->toContain('md:grid-cols-3')->toContain('xl:grid-cols-4')->toContain('2xl:grid-cols-5');

    // 40 a page is whole rows of 2, 4 and 5 (8 rows of 5); 3 across leaves one card in the last row, as it must for any page size that suits 4 and 5.
    expect(ListingQuery::PER_PAGE % 5)->toBe(0)->and(intdiv(ListingQuery::PER_PAGE, 5))->toBe(8);
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

it('draws the sort drop-down itself: no browser arrow, its own chevron, a teal border on hover and a teal ring on focus', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match('#<select[^>]*data-sort-select[^>]*>#s', $html, $select);
    preg_match('#<svg[^>]*data-sort-chevron[^>]*>#', $html, $chevron);

    expect($select[0] ?? '')->toContain('id="sort"')->toContain('wire:model.live="sort"')
        ->toContain('appearance-none')->toContain('pr-9')->toContain('rounded-md')->toContain('cursor-pointer')
        ->toContain('border-border-strong')->toContain('hover:border-brand-teal')->toContain('focus:ring-2')
        // The chevron does not catch clicks, so the whole select opens, and a screen reader skips it.
        ->and($chevron[0] ?? '')->toContain('pointer-events-none')->toContain('aria-hidden="true"')
        ->and($html)->toContain('<label for="sort"');
});

it('sets the product name on a card at 15px with 6px of space above and below', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match_all('#<h3 class="([^"]*)"#', $html, $headings);

    expect($headings[1])->not->toBeEmpty();

    foreach ($headings[1] as $classes) {
        expect($classes)->toContain('text-[0.84375rem]')->toContain('font-semibold')->toContain('py-1.5');
    }
});

it('shows the safety class as a badge in the top right of the picture on each card that has one', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match_all('#<li[^>]*wire:key="product-(\d+)".*?</li>\s*(?=<li[^>]*wire:key|</ul>)#s', $html, $cards, PREG_SET_ORDER);

    expect($cards)->not->toBeEmpty();

    $withClass = 0;

    foreach ($cards as [$card, $id]) {
        $class = trim((string) Product::query()->findOrFail((int) $id)->attr('safety_class'));

        if ($class === '') {
            // No class, no badge.
            expect($card)->not->toContain('data-card-class');

            continue;
        }

        $withClass++;

        preg_match('#<h3.*?</h3>#s', $card, $heading);

        // A badge in the top right corner of the picture, on white, and not in the name line.
        expect($card)->toMatch('#<span class="[^"]*absolute right-2 top-2[^"]*bg-white[^"]*text-brand-navy[^"]*" data-card-class>'.preg_quote($class, '#').'</span>#')
            ->and($heading[0] ?? '')->not->toContain('data-card-class')
            ->and(strpos($card, 'data-card-class'))->toBeLessThan(strpos($card, '<h3'));
    }

    expect($withClass)->toBeGreaterThan(0);
});

it('shows one non-interactive colour swatch per colour under each product', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match_all('#<li[^>]*wire:key="product-(\d+)".*?</li>\s*(?=<li[^>]*wire:key|</ul>)#s', $html, $cards, PREG_SET_ORDER);

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

        // One per swatch element. The component renders the attribute as data-swatch="data-swatch", so the
        // attribute name (followed by =) is skipped and only its value counts, and a bare data-swatch counts too.
        expect(preg_match_all('/\bdata-swatch(?![\w=-])/', $card))->toBe($expected);

        preg_match('#<p[^>]*data-swatches.*?</p>#s', $card, $swatches);

        // Not clickable, and nothing marks a colour as selected.
        expect($swatches[0] ?? '')->not->toMatch('#<(a|button|input)\b#')
            ->and($swatches[0] ?? '')->not->toContain('aria-pressed')
            ->and($swatches[0] ?? '')->not->toContain('aria-current')
            ->and($swatches[0] ?? '')->not->toContain('ring');
    }
});

it('says what a listing narrowed to an industry is in its heading, and shows the filters as chips', function (): void {
    $html = $this->get(route('category', 'safety-footwear').'?industry[0]=Mining&brand[0]=Maxguard')->assertOk()->getContent();

    preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $heading);
    preg_match('#<ul[^>]*data-active-filters.*?</ul>#s', $html, $chips);

    expect(trim(strip_tags($heading[1] ?? '')))->toBe('Safety Footwear for Mining')
        ->and($chips[0] ?? '')->toContain('data-active-filter="industry"')->toContain('Mining')
        ->toContain('data-active-filter="brand"')->toContain('Maxguard')
        ->toContain('Clear all')
        // The page title says it too, and the canonical stays the plain category.
        ->and($html)->toContain('<title>Safety Footwear for Mining')
        ->and($html)->toContain('<link rel="canonical" href="'.route('category', 'safety-footwear').'">');
});

it('joins several industries in the heading', function (): void {
    $html = $this->get(route('category', 'safety-footwear').'?industry[0]=Mining&industry[1]=Logistics')->assertOk()->getContent();

    preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $heading);

    expect(trim(strip_tags($heading[1] ?? '')))->toBe('Safety Footwear for Mining and Logistics');
});

it('shows no filter chips on a listing that is not filtered', function (): void {
    $this->get(route('category', 'safety-footwear'))->assertOk()->assertDontSee('data-active-filters', false);
});

it('removes one filter from its chip, all of them, the price range and in stock only', function (): void {
    Livewire::withQueryParams(['industry' => ['Mining', 'Logistics'], 'brand' => ['Maxguard'], 'in_stock' => '1'])
        ->test(CategoryListing::class, ['collectionId' => CatalogueKeys::collection('safety-footwear')->id])
        ->assertSee('Safety Footwear for Mining and Logistics')
        ->call('removeFilter', 'industry', 'Mining')
        ->assertSet('industry', ['Logistics'])
        ->assertSee('Safety Footwear for Logistics')
        ->call('removeInStockOnly')
        ->assertSet('in_stock', false)
        ->call('removeFilter', 'brand', 'Maxguard')
        ->assertSet('brand', [])
        // Only the known facets can be changed from a chip.
        ->call('removeFilter', 'sort', 'price-asc')
        ->assertSet('sort', 'relevance')
        ->call('clearFilters')
        ->assertSet('industry', [])
        ->assertDontSee('data-active-filters', false);
});

it('puts the card picture on the spotlight backdrop', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    expect($html)->toContain('card-backdrop');

    $css = (string) file_get_contents(resource_path('css/app.css'));

    // A radial spotlight, 30% lighter than it was (each teal stop mixed 30% of the way to white), deeper on hover.
    expect($css)->toContain('.card-backdrop')->toContain('radial-gradient')
        ->toContain('#F2F9F9 65%, #DDF1EF 100%')
        ->toContain('#DDF2F0 45%, #CAEBE9 70%, #AFE0DB 100%');
});

it('shows the Industry filter first, above the other filters', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match_all('#<fieldset data-facet="([^"]+)">#', $html, $facets);

    // The desktop and the phone copy of the filters are both on the page: the first facet of the first copy is Industry.
    expect($facets[1])->not->toBeEmpty()
        ->and($facets[1][0])->toBe('industry');
});

it('gives the filter sidebar 16.16rem, 1% more than 16rem, from lg', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match('#<div class="[^"]*lg:grid-cols-\[([^\]]+)\][^"]*">\s*<aside aria-label="Filters">#', $html, $layout);

    expect($layout[1] ?? '')->toBe('16.16rem_1fr');
});

it('keeps the left edge of each checkbox inside its scrolling list, and the list where it was', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match_all('#<ul class="([^"]*)" data-facet-list>#', $html, $lists);

    expect($lists[1])->not->toBeEmpty();

    foreach ($lists[1] as $list) {
        // Room on the left for the checkbox's edge and focus ring (pl-1.5), taken back with -ml-1.5 so the checkboxes and words stay put.
        expect($list)->toContain('overflow-y-auto')->toContain('pl-1.5')->toContain('-ml-1.5')->toContain('pr-1')->toContain('max-h-64');
    }
});
