<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Variants\VariantSelection;
use App\Livewire\Catalogue\CategoryListing;
use App\Livewire\Catalogue\ProductPage;
use App\Models\User;
use Livewire\Livewire;
use Lunar\Models\Product;
use Lunar\Models\Url as LunarUrl;
use Tests\Support\SearchIndex;

/**
 * Prices are shown to signed-in customers only. Guests see a prompt to log in
 * in place of every price, and cannot learn prices by filtering or sorting.
 */
beforeEach(function (): void {
    SearchIndex::use();
});

/** The text a visitor reads: markup and scripts removed. */
function pageText(string $html): string
{
    return strip_tags((string) preg_replace('#<script.*?</script>#s', '', $html));
}

/** The "N products" count of a listing page. */
function listingTotal(string $html): int
{
    preg_match('#id="results-heading"[^>]*>\s*(\d+)\s+products?#', $html, $matches);

    return (int) ($matches[1] ?? -1);
}

/** The product names in a listing grid, in order. */
function guestGridNames(string $html): array
{
    preg_match('#<ul[^>]*data-testid="product-grid".*?</ul>#s', $html, $grid);
    preg_match_all('#<h3[^>]*>\s*<a[^>]*>\s*(.*?)\s*</a>#s', $grid[0] ?? '', $matches);

    return array_map(trim(...), $matches[1]);
}

it('shows a guest no price, quantity field or add to cart button on a product page', function (string $slug): void {
    $html = $this->get(route('product', $slug))->assertOk()->getContent();

    expect($html)->not->toContain('data-price>')
        ->and($html)->not->toContain('data-testid="price"')
        ->and(pageText($html))->not->toMatch('/£\s?\d/')
        ->and($html)->not->toContain('data-quantity')
        ->and($html)->not->toContain('data-add-to-cart')
        ->and($html)->not->toContain('id="quantity"')
        // The rest of the page is unchanged: options and stock still show.
        ->and($html)->toContain('data-testid="stock"')
        ->and($html)->toContain('data-option=');
})->with(['safety-jogger-ligero', 'amblers-safety-fs9']);

it('shows a guest every size, in stock or not, and none of them clickable', function (string $slug): void {
    $product = Product::query()->findOrFail(
        LunarUrl::query()->where('element_type', Product::morphName())->where('slug', $slug)->firstOrFail()->element_id,
    );
    $sizes = collect(VariantSelection::forProduct($product)->options())->firstWhere('handle', 'shoe_size')['values'];

    expect(count($sizes))->toBeGreaterThan(1);

    $guest = $this->get(route('product', $slug))->assertOk()->getContent();

    expect(substr_count($guest, 'data-size-readonly'))->toBe(count($sizes))
        ->and($guest)->not->toContain("choose('shoe_size'")
        ->and($guest)->not->toContain('line-through')
        ->and($guest)->not->toContain('(out of stock)')
        ->and($guest)->not->toContain('title="Out of stock"');

    // A customer gets buttons, so the same sizes are clickable for them.
    $this->actingAs(User::factory()->create(), 'web');

    $customer = $this->get(route('product', $slug))->assertOk()->getContent();

    expect($customer)->toContain("choose('shoe_size'")
        ->and($customer)->not->toContain('data-size-readonly');
})->with(['safety-jogger-ligero', 'maxguard-sx310']);

it('does not let a guest pick a size through a Livewire update, but still lets them pick a colour', function (): void {
    $component = Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero']);

    $component->call('choose', 'shoe_size', '8')->assertSet('choices', []);

    $component->call('choose', 'colour', 'black')->assertSet('colour', 'black');
});

it('leaves prices out of the structured data for a guest', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
    $data = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

    expect($data['@type'])->toBe('Product')
        ->and($data)->not->toHaveKey('offers')
        ->and($matches[1])->not->toContain('68.90')
        ->and($matches[1])->not->toContain('priceSpecification');
});

it('shows no price on a listing or on search results to a guest', function (array $route): void {
    $html = $this->get(route(...$route))->assertOk()->getContent();

    expect(listingTotal($html))->toBeGreaterThan(0)
        ->and($html)->not->toContain('data-price>')
        ->and(pageText($html))->not->toMatch('/£\s?\d/');
})->with([
    [['category', 'safety-footwear']],
    [['search', ['q' => 'boots']]],
    [['brands.show', 'amblers']],
]);

it('shows no price on the featured products of the home page to a guest', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-products.*?</section>#s', $html, $section);

    expect($section[0] ?? '')->toContain('data-home-product>')
        ->and($section[0])->not->toContain('data-price>')
        ->and(pageText($section[0]))->not->toMatch('/£\s?\d/');
});

it('hides the price filter and the price sorts from a guest', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    expect($html)->not->toContain('Price (ex VAT)')
        ->and($html)->not->toContain('price-min')
        ->and($html)->not->toContain('value="price-asc"')
        ->and($html)->not->toContain('value="price-desc"')
        ->and($html)->toContain('value="newest"');
});

it('ignores a price range in the URL for a guest, so prices cannot be probed', function (): void {
    $plain = $this->get(route('category', 'safety-footwear'))->getContent();
    $probed = $this->get(route('category', 'safety-footwear').'?price_min=80&price_max=85')->assertOk()->getContent();

    expect(listingTotal($probed))->toBe(listingTotal($plain))
        ->and(listingTotal($probed))->toBeGreaterThan(0)
        ->and($probed)->not->toContain('No products match');
});

it('ignores a price sort in the URL for a guest', function (string $sort): void {
    $plain = $this->get(route('category', 'safety-footwear'))->getContent();
    $sorted = $this->get(route('category', 'safety-footwear').'?sort='.$sort)->assertOk()->getContent();

    expect(guestGridNames($sorted))->toBe(guestGridNames($plain));
})->with(['price-asc', 'price-desc']);

it('ignores a price filter or sort set later through a Livewire update for a guest', function (): void {
    $component = Livewire::test(CategoryListing::class, ['collectionId' => CatalogueKeys::collection('safety-footwear')->id]);
    $before = guestGridNames($component->html());

    $component->set('price_min', '80')->set('price_max', '85');
    expect(guestGridNames($component->html()))->toBe($before);

    $component->set('sort', 'price-desc');
    expect(guestGridNames($component->html()))->toBe($before)
        ->and($component->get('sort'))->toBe('relevance')
        ->and($component->get('price_min'))->toBe('');
});

it('shows a signed-in customer prices, the price filter and the price sorts', function (): void {
    $this->actingAs(User::factory()->create(), 'web');

    $listing = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();
    $product = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();
    $filtered = $this->get(route('category', 'safety-footwear').'?price_min=65&price_max=70')->assertOk()->getContent();

    expect($listing)->toContain('data-price>')
        ->and($listing)->toContain('Price (ex VAT)')
        ->and($listing)->toContain('value="price-asc"')
        ->and($product)->toContain('£68.90')
        ->and($product)->toContain('data-quantity')
        ->and($product)->toContain('data-add-to-cart')
        ->and(guestGridNames($filtered))->toBe(['LIGERO']);
});

it('hides prices again from a customer once they log out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->get(route('product', 'safety-jogger-ligero'))->assertSee('£68.90');

    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->assertDontSee('£68.90');
});
