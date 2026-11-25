<?php

declare(strict_types=1);

use App\Domain\Catalogue\Markets\ShipsTo;
use App\Domain\Catalogue\Search\IndexSettings;
use App\Domain\Catalogue\Search\StorefrontProductIndexer;
use App\Support\Store;
use Lunar\Models\Product;
use Meilisearch\Client;
use Tests\Support\SearchIndex;

// Where each product can be delivered: Safety Jogger (SJ) in the UK and the EU, Footsure (FS) in the UK only, worked
// out from the vendor code at the start of the SKUs and recorded in the search index as `ships_to` (prompts/ships-to-market.md,
// Phase 1). Nothing customers see changes, and EU delivery itself is not built.

// --- The rule ---------------------------------------------------------------------------------

it('puts Safety Jogger in the UK and the EU, and Footsure in the UK only', function (): void {
    $markets = new ShipsTo;

    expect($markets->forSku('SJ-1492552'))->toBe(['uk', 'eu'])
        ->and($markets->forSku('FS-00052-00095-09'))->toBe(['uk']);
});

it('is UK only for anything it does not recognise: an unknown vendor, no vendor code, a blank SKU', function (string $sku): void {
    expect((new ShipsTo)->forSku($sku))->toBe(['uk']);
})->with(['an unknown vendor' => 'XY-123', 'no vendor code' => '1492552', 'a blank SKU' => '', 'spaces' => '   ', 'a dash first' => '-123']);

it('reads the vendor code without regard to case or spaces', function (): void {
    expect((new ShipsTo)->forSku(' sj-1492552'))->toBe(['uk', 'eu']);
});

it('gives a product with several SKUs the markets they all share, and UK only when it has none', function (): void {
    $markets = new ShipsTo;

    expect($markets->forSkus(['SJ-1', 'SJ-2', 'SJ-3']))->toBe(['uk', 'eu'])
        // Mixed vendors should never happen: the product then goes only where every SKU can.
        ->and($markets->forSkus(['SJ-1', 'FS-1']))->toBe(['uk'])
        ->and($markets->forSkus([]))->toBe(['uk']);
});

it('follows the map in the config, so a new vendor is one line', function (): void {
    config(['store.markets.vendors' => ['SJ' => ['uk', 'eu'], 'FS' => ['uk'], 'ab' => ['UK', ' EU ']]]);

    expect((new ShipsTo)->forSku('AB-9'))->toBe(['uk', 'eu']);

    // A vendor not in the map is UK only; so is every vendor when the default is changed.
    config(['store.markets.default' => ['uk']]);

    expect((new ShipsTo)->forSku('ZZ-9'))->toBe(['uk']);
});

// --- Real products ----------------------------------------------------------------------------

it('works it out for the fixture products: EU only when every SKU is Safety Jogger', function (): void {
    $markets = new ShipsTo;
    $seen = ['eu' => 0, 'uk-only' => 0];

    foreach (Product::query()->with('variants')->get() as $product) {
        $skus = $product->variants->pluck('sku');
        $everySj = $skus->isNotEmpty() && $skus->every(fn (string $sku): bool => str_starts_with($sku, 'SJ-'));

        expect($markets->deliversToEu($product))->toBe($everySj, 'Product '.$product->id)
            ->and($markets->forProduct($product))->toBe($everySj ? ['uk', 'eu'] : ['uk']);

        $seen[$everySj ? 'eu' : 'uk-only']++;
    }

    // The fixtures have both vendors, so both outcomes are really tested.
    expect($seen['eu'])->toBeGreaterThan(0)->and($seen['uk-only'])->toBeGreaterThan(0);
});

// --- The index --------------------------------------------------------------------------------

it('carries ships_to on every product document, and the setting makes it filterable', function (): void {
    $indexer = app(StorefrontProductIndexer::class);
    $sj = Product::query()->whereHas('variants', fn ($query) => $query->where('sku', 'like', 'SJ-%'))->firstOrFail();
    $fs = Product::query()->whereHas('variants', fn ($query) => $query->where('sku', 'like', 'FS-%'))->firstOrFail();

    expect($indexer->toSearchableArray($sj)['ships_to'])->toBe(['uk', 'eu'])
        ->and($indexer->toSearchableArray($fs)['ships_to'])->toBe(['uk'])
        ->and(IndexSettings::filterable())->toContain('ships_to')
        ->and($indexer->getFilterableFields())->toContain('ships_to')
        // The settings changed, so the version moved and the deploy applies them (catalogue:sync-search-settings).
        ->and(IndexSettings::VERSION)->toBeGreaterThanOrEqual(3);
});

it('filters the live index by market: the EU gets only the Safety Jogger products', function (): void {
    SearchIndex::use();

    $index = app(Client::class)->index((new Product)->searchableAs());
    $ids = fn (string $market): array => collect($index->search('', ['filter' => 'status = published AND ships_to = '.$market, 'limit' => 1000, 'attributesToRetrieve' => ['id']])->getHits())
        ->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

    $uk = $ids('uk');
    $eu = $ids('eu');
    $expectedEu = Product::query()->where('status', 'published')->with('variants')->get()
        ->filter(fn (Product $product): bool => (new ShipsTo)->deliversToEu($product))
        ->pluck('id')->sort()->values()->all();

    // Everything ships to the UK; the EU list is the Safety Jogger products and nothing else.
    expect($eu)->toBe($expectedEu)->and($eu)->not->toBeEmpty()
        ->and(count($uk))->toBeGreaterThan(count($eu))
        ->and(array_diff($eu, $uk))->toBe([]);
});

// --- The switch -------------------------------------------------------------------------------

it('has EU delivery switched off by default, and reads the switch through Store', function (): void {
    expect(config('store.eu_delivery'))->toBeFalse()->and(Store::euDelivery())->toBeFalse();

    config(['store.eu_delivery' => true]);

    expect(Store::euDelivery())->toBeTrue();
});
