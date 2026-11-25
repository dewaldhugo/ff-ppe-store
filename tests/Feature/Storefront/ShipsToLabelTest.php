<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Livewire\Catalogue\CategoryListing;
use App\Models\User;
use Livewire\Livewire;
use Lunar\Models\Product;
use Tests\Support\SearchIndex;

// The "where it ships" label on the product page, under the stock line (<x-storefront.ships-to>): "Delivery: UK, EU" for
// Safety Jogger, "Delivery: UK" for Footsure, each with the flag of every market it names. Never on the cards in a listing.
// Shown only while EU delivery is switched on (STORE_EU_DELIVERY, off by default), because the store delivers to the UK only
// today (docs/cart.md, Where products ship).

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
            ->not->toContain('Delivery: UK')
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

    expect($sj)->toContain('data-ships-to="uk,eu"')->toContain('Delivery: UK, EU')
        ->and($fs)->toContain('data-ships-to="uk"')->toContain('Delivery: UK')->not->toContain('Delivery: UK, EU');

    // A flag for each market the product ships to: both for Safety Jogger, the UK's alone for Footsure, each a real file.
    preg_match_all('#data-flag="([a-z]+)"#', $sj, $sjFlags);
    preg_match_all('#data-flag="([a-z]+)"#', $fs, $fsFlags);

    expect($sjFlags[1])->toBe(['uk', 'eu'])
        ->and($fsFlags[1])->toBe(['uk'])
        ->and($sj)->toContain(asset('images/flags/gb.svg'))->toContain(asset('images/flags/eu.svg'))
        ->and($fs)->toContain(asset('images/flags/gb.svg'))->not->toContain(asset('images/flags/eu.svg'));
});

it('puts the label under the stock line, with no border round the flags', function (): void {
    config(['store.eu_delivery' => true]);

    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<img[^>]*data-flag="uk"[^>]*>#', $html, $uk);
    preg_match('#<img[^>]*data-flag="eu"[^>]*>#', $html, $eu);

    // Both flags are 33px wide with a slight radius, the Union Flag 22px high and the EU's 23px, which looks the same size
    // (the Union Flag is 2:1, so it is cropped to fit).
    expect(strpos($html, 'data-testid="stock"'))->toBeLessThan(strpos($html, 'data-ships-to'))
        ->and($uk[0] ?? '')->toContain('h-[22px]')->toContain('w-[33px]')->toContain('rounded-[3px]')->toContain('object-cover')->not->toContain('border')
        ->and($eu[0] ?? '')->toContain('h-[23px]')->toContain('w-[33px]')->toContain('rounded-[3px]')->toContain('object-cover')->not->toContain('border');
});

it('does not label the cards in a listing, or the related products', function (): void {
    SearchIndex::use();
    config(['store.eu_delivery' => true]);

    $html = Livewire::test(CategoryListing::class, ['collectionId' => CatalogueKeys::collection('safety-footwear')->id])->html();

    preg_match('#<ul[^>]*data-testid="product-grid".*?</ul>#s', $html, $grid);

    expect($grid[0] ?? '')->toContain('data-view-product')
        ->not->toContain('data-ships-to')
        ->not->toContain('data-flag')
        ->not->toContain('Delivery: UK');
});
