<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Livewire\Catalogue\CategoryListing;
use App\Models\User;
use Livewire\Livewire;
use Lunar\Models\Product;
use Tests\Support\SearchIndex;

// The "where it ships" label on the product page and the listing cards (<x-storefront.ships-to>): "Delivers to the UK and
// the EU" for Safety Jogger, "UK delivery only" for Footsure. Shown only while EU delivery is switched on (STORE_EU_DELIVERY, off by
// default), because the store delivers to the UK only today (docs/cart.md, Where products ship).

beforeEach(function (): void {
    config()->set('store.noindex', true);
    $this->actingAs(User::factory()->create(), 'web');
});

it('shows nothing about where products ship while EU delivery is off, on a product page or in a listing', function (): void {
    SearchIndex::use();

    expect(config('store.eu_delivery'))->toBeFalse();

    $page = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();
    $listing = Livewire::test(CategoryListing::class, ['collectionId' => CatalogueKeys::collection('safety-footwear')->id])->html();

    foreach ([$page, $listing] as $html) {
        expect($html)->not->toContain('data-ships-to')
            ->not->toContain('UK delivery only')
            ->not->toContain('Delivers to the UK and the EU')
            ->not->toContain('images/flags');
    }
});

it('draws the flags as image files: the Union Flag and the EU flag with its twelve stars', function (): void {
    $gb = simplexml_load_file(public_path('images/flags/gb.svg'));
    $eu = simplexml_load_file(public_path('images/flags/eu.svg'));

    expect($gb)->not->toBeFalse()->and($eu)->not->toBeFalse()
        ->and((string) $gb['viewBox'])->toBe('0 0 60 30')
        ->and((string) $eu['viewBox'])->toBe('0 0 810 540')
        ->and(count($eu->g->polygon))->toBe(12);
});

it('labels a Safety Jogger product UK and EU and a Footsure product UK only on the product page, when switched on', function (): void {
    config(['store.eu_delivery' => true]);

    $footsure = Product::query()->where('status', 'published')->with(['variants', 'defaultUrl'])->get()
        ->first(fn (Product $product): bool => $product->variants->isNotEmpty() && $product->variants->every(fn ($variant): bool => str_starts_with($variant->sku, 'FS-')));

    expect($footsure)->not->toBeNull();

    $sj = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();
    $fs = $this->get(route('product', $footsure->defaultUrl->slug))->assertOk()->getContent();

    expect($sj)->toContain('data-ships-to="uk,eu"')->toContain('Delivers to the UK and the EU')->not->toContain('UK delivery only')
        ->and($fs)->toContain('data-ships-to="uk"')->toContain('UK delivery only')->not->toContain('Delivers to the UK and the EU');

    // A flag for each market the product ships to: both for Safety Jogger, the UK's alone for Footsure, each a real file.
    preg_match_all('#data-flag="([a-z]+)"#', $sj, $sjFlags);
    preg_match_all('#data-flag="([a-z]+)"#', $fs, $fsFlags);

    expect($sjFlags[1])->toBe(['uk', 'eu'])
        ->and($fsFlags[1])->toBe(['uk'])
        ->and($sj)->toContain(asset('images/flags/gb.svg'))->toContain(asset('images/flags/eu.svg'))
        ->and($fs)->toContain(asset('images/flags/gb.svg'))->not->toContain(asset('images/flags/eu.svg'));
});

it('labels the cards in a listing, one label a card', function (): void {
    SearchIndex::use();
    config(['store.eu_delivery' => true]);

    $html = Livewire::test(CategoryListing::class, ['collectionId' => CatalogueKeys::collection('safety-footwear')->id])->html();

    preg_match('#<ul[^>]*data-testid="product-grid".*?</ul>#s', $html, $grid);
    preg_match_all('#<li wire:key="product-\d+"#', $grid[0], $cards);
    preg_match_all('#data-ships-to="([^"]*)"#', $grid[0], $labels);
    preg_match_all('#data-flag="([a-z]+)"#', $grid[0], $flags);

    // Every card has exactly one label, and the fixtures have both vendors, so both wordings appear.
    expect(count($cards[0]))->toBeGreaterThan(0)
        ->and(count($labels[1]))->toBe(count($cards[0]))
        ->and($labels[1])->toContain('uk,eu')->toContain('uk');

    // A flag for every market named: two for each "uk,eu" card, one for each "uk" card.
    $expectedFlags = array_sum(array_map(fn (string $markets): int => count(explode(',', $markets)), $labels[1]));

    expect(count($flags[1]))->toBe($expectedFlags);
});
