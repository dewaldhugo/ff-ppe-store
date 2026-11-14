<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Search\CatalogueSearch;
use App\Domain\Catalogue\Search\ListingQuery;
use App\Domain\Catalogue\Search\ListingResult;
use App\Domain\Pricing\VatDisplayMode;
use Lunar\Models\Product;
use Tests\Support\SearchIndex;

/*
 * Runs against the local Meilisearch instance, in the `testing_` index.
 *
 * The seeded fixture has five products under Safety Footwear:
 *
 *   Ligero   Safety Jogger   S1P  SRC CI ESD METAL_FREE   ex 68.90  inc 82.68
 *   SX310    Maxguard        S3   SRC HI HRO WR CI ...    ex 59.99  inc 71.99  (no stock)
 *   FS9      Amblers Safety  SBP  P SRA                   ex 52.48  inc 52.48  (zero rated)
 *   FS65     Amblers Safety  S1P  P SRC                   ex 40.48  inc 48.58
 *   FS30C    Centek          -    SR                      ex 40.48  inc 40.48  (zero rated)
 */

beforeEach(function (): void {
    SearchIndex::use();
});

/**
 * @param  array<string, mixed>  $input
 */
function listing(array $input = [], ?string $collection = 'safety-footwear', VatDisplayMode $vatMode = VatDisplayMode::IncVat): ListingResult
{
    $collectionId = $collection === null ? null : CatalogueKeys::collection($collection)->id;

    return app(CatalogueSearch::class)->search(ListingQuery::fromArray($input, $collectionId, $vatMode));
}

/**
 * @return list<string>
 */
function names(ListingResult $result): array
{
    $products = Product::query()->findMany($result->productIds)->keyBy('id');

    return array_map(fn (int $id): string => $products[$id]->translateAttribute('name'), $result->productIds);
}

it('lists a collection together with its descendants', function (): void {
    $result = listing();

    expect($result->total)->toBe(5)
        ->and(names($result))->toEqualCanonicalizing(['LIGERO', 'SX310', 'FS9', 'FS65', 'FS30C']);
});

it('lists a leaf collection and a range', function (string $collection, array $expected): void {
    expect(names(listing(collection: $collection)))->toEqualCanonicalizing($expected);
})->with([
    ['safety-boots', ['FS9', 'FS30C']],
    ['occupational-footwear', ['Classic']],
    ['clearance', ['FS9', 'FS65', 'Eva']],
]);

it('returns Ligero and FS65 for S1P and SRC', function (): void {
    $result = listing(['safety_class' => ['S1P'], 'features' => ['SRC']]);

    expect(names($result))->toEqualCanonicalizing(['LIGERO', 'FS65']);
});

it('ORs values within a facet and ANDs across facets', function (): void {
    expect(names(listing(['safety_class' => ['S1P', 'SBP']])))->toEqualCanonicalizing(['LIGERO', 'FS65', 'FS9'])
        ->and(names(listing(['safety_class' => ['S3', 'SBP'], 'brand' => ['Amblers Safety']])))->toBe(['FS9']);
});

it('counts a facet without its own selection, and other facets with it', function (): void {
    $result = listing(['safety_class' => ['S1P']]);

    // Own facet: every class is still on offer.
    expect($result->counts('safety_class'))->toBe(['S1P' => 2, 'S3' => 1, 'SBP' => 1])
        // Other facets: narrowed to the S1P products.
        ->and($result->counts('brand'))->toBe(['Amblers Safety' => 1, 'Safety Jogger' => 1])
        ->and($result->counts('features'))->toBe(['CI' => 1, 'ESD' => 1, 'METAL_FREE' => 1, 'P' => 1, 'SRC' => 2]);
});

it('keeps counts disjunctive with two facets selected', function (): void {
    $result = listing(['safety_class' => ['S1P'], 'features' => ['SRC']]);

    // Classes among SRC products; features among S1P products.
    expect($result->counts('safety_class'))->toBe(['S1P' => 2, 'S3' => 1])
        ->and($result->counts('features'))->toBe(['CI' => 1, 'ESD' => 1, 'METAL_FREE' => 1, 'P' => 1, 'SRC' => 2])
        ->and($result->total)->toBe(2);
});

it('marks selected facet values', function (): void {
    $values = collect(listing(['brand' => ['Centek']])->facets['brand'])->keyBy('value');

    expect($values['Centek']->selected)->toBeTrue()
        ->and($values['Maxguard']->selected)->toBeFalse()
        ->and($values['Amblers Safety']->count)->toBe(2);
});

it('filters by price range in the inc VAT mode', function (): void {
    expect(names(listing(['price_min' => 80, 'price_max' => 85], vatMode: VatDisplayMode::IncVat)))->toBe(['LIGERO'])
        ->and(listing(['price_min' => 65, 'price_max' => 70], vatMode: VatDisplayMode::IncVat)->total)->toBe(0);
});

it('filters by price range in the ex VAT mode', function (): void {
    expect(names(listing(['price_min' => 65, 'price_max' => 70], vatMode: VatDisplayMode::ExVat)))->toBe(['LIGERO'])
        ->and(listing(['price_min' => 80, 'price_max' => 85], vatMode: VatDisplayMode::ExVat)->total)->toBe(0);
});

it('treats zero rated products the same in both VAT modes', function (): void {
    $range = ['price_min' => 52, 'price_max' => 53];

    expect(names(listing($range, vatMode: VatDisplayMode::IncVat)))->toBe(['FS9'])
        ->and(names(listing($range, vatMode: VatDisplayMode::ExVat)))->toBe(['FS9']);
});

it('reports the price bounds ignoring the price filter', function (): void {
    $inc = listing(['price_min' => 80], vatMode: VatDisplayMode::IncVat);
    $ex = listing(['price_min' => 65], vatMode: VatDisplayMode::ExVat);

    expect([$inc->priceFloor, $inc->priceCeiling])->toBe([4048, 8268])
        ->and([$ex->priceFloor, $ex->priceCeiling])->toBe([4048, 6890]);
});

it('requires the colour and size combination to be in stock', function (): void {
    // Ligero has Black in stock, and UK 10 in stock, but Black 10 is not.
    expect(names(listing(['colour' => ['black']], collection: null)))->toContain('LIGERO')
        ->and(names(listing(['size' => ['10']], collection: null)))->toContain('LIGERO')
        ->and(names(listing(['colour' => ['black'], 'size' => ['10']], collection: null)))->not->toContain('LIGERO')
        // Navy 10 and Black 9 are both in stock.
        ->and(names(listing(['colour' => ['navy'], 'size' => ['10']], collection: null)))->toBe(['LIGERO'])
        ->and(names(listing(['colour' => ['black'], 'size' => ['9']], collection: null)))->toBe(['LIGERO']);
});

it('excludes out of stock products when in stock only is selected', function (): void {
    expect(names(listing()))->toContain('SX310')
        ->and(names(listing(['in_stock' => '1'])))->toEqualCanonicalizing(['LIGERO', 'FS9', 'FS65', 'FS30C']);
});

it('only offers sizes and colours that are in stock, sizes in numeric order', function (): void {
    $result = listing(collection: 'slippers');

    // Eva: only UK 3 in stock. Exeter: 6, 7, 9, 11, 12, 13.
    expect(array_column($result->facets['size'], 'value'))->toBe(['3', '6', '7', '9', '11', '12', '13'])
        ->and($result->counts('colour'))->toBe(['blue' => 1, 'brown' => 1]);
});

it('sorts by price in the current VAT mode', function (): void {
    expect(names(listing(['sort' => 'price-asc']))[0])->toBe('FS30C')
        ->and(names(listing(['sort' => 'price-desc'])))->toBe(['LIGERO', 'SX310', 'FS9', 'FS65', 'FS30C'])
        ->and(names(listing(['sort' => 'price-desc'], vatMode: VatDisplayMode::ExVat))[0])->toBe('LIGERO');
});

it('searches by term', function (string $term, string $expected): void {
    expect(names(listing(['q' => $term], collection: null)))->toContain($expected);
})->with([
    ['ligero', 'LIGERO'],
    ['goodyear welted', 'FS9'],
    ['maxguard', 'SX310'],
    ['S1P', 'LIGERO'],
]);

it('paginates', function (): void {
    $query = new ListingQuery(perPage: 3, page: 2);
    $result = app(CatalogueSearch::class)->search($query);

    expect($result->total)->toBe(8)
        ->and($result->lastPage)->toBe(3)
        ->and($result->page)->toBe(2)
        ->and($result->productIds)->toHaveCount(3);
});

it('never returns draft products', function (): void {
    $product = Product::query()->whereHas('urls', fn ($query) => $query->where('slug', 'centek-fs30c'))->sole();

    try {
        $product->update(['status' => 'draft']);
        SearchIndex::settle();

        expect(names(listing()))->not->toContain('FS30C');
    } finally {
        // The database rolls back after the test; the index does not.
        $product->update(['status' => 'published']);
        SearchIndex::settle();
    }

    expect(names(listing()))->toContain('FS30C');
});

it('applies index settings once and then reports them as current', function (): void {
    $this->artisan('catalogue:sync-search-settings')
        ->expectsOutputToContain('already has settings version')
        ->assertSuccessful();
});

it('builds a listing query from a query string, dropping junk', function (): void {
    $query = ListingQuery::fromArray([
        'brand' => ['GBS', '', 'GBS'],
        'size' => '9',
        'unknown' => ['x'],
        'price_min' => '20',
        'price_max' => 'abc',
        'in_stock' => '1',
        'sort' => 'nonsense',
        'page' => '-4',
        'q' => '  boots ',
    ]);

    expect($query->facets)->toBe(['brand' => ['GBS'], 'size' => ['9']])
        ->and($query->priceMin)->toBe(2000)
        ->and($query->priceMax)->toBeNull()
        ->and($query->inStockOnly)->toBeTrue()
        ->and($query->sort->value)->toBe('relevance')
        ->and($query->page)->toBe(1)
        ->and($query->term)->toBe('boots');
});
