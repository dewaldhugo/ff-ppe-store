<?php

declare(strict_types=1);

use App\Domain\Favourites\Favourites;
use App\Models\Favourite;
use App\Models\User;
use Illuminate\Database\QueryException;
use Lunar\Admin\Models\Staff;
use Tests\Support\CatalogueFactory;

// Favourites: a bookmark button on the product page, saved per signed-in customer,
// and a /favourites page. A guest sees no button (docs/accounts.md).

function favouriteProduct(string $name = 'Heart Boot', string $status = 'published')
{
    return CatalogueFactory::product($name, [['stock' => 5]], $status, 5000);
}

it('shows a guest no favourites button anywhere, and saves nothing', function (): void {
    $product = favouriteProduct();

    $html = $this->get(route('product', $product->defaultUrl->slug))->assertOk()->getContent();

    expect($html)->not->toContain('data-favourite-form')
        ->and($html)->not->toContain('data-favourite-button')
        ->and($html)->not->toContain('data-favourite-wrap')
        ->and($html)->not->toContain('Add to favourites');

    $this->post(route('favourites.toggle', $product->id))->assertRedirect(route('login'));
    expect(Favourite::count())->toBe(0);
});

it('shows the button on the product page only, never on cards', function (): void {
    $this->actingAs(User::factory()->create(), 'web');
    $product = favouriteProduct();

    foreach ([route('home'), route('shop'), route('search', ['q' => 'Heart']), route('favourites')] as $url) {
        expect($this->get($url)->getContent())->not->toContain('data-favourite-button');
    }

    expect($this->get(route('product', $product->defaultUrl->slug))->getContent())->toContain('data-favourite-button');
});

it('shows a signed-in customer the bookmark button on the product page, off until it is saved', function (): void {
    $this->actingAs(User::factory()->create(), 'web');
    $product = favouriteProduct();

    $this->get(route('product', $product->defaultUrl->slug))->assertOk()
        ->assertSee('data-favourite-form', false)
        ->assertSee('Add to favourites')
        ->assertSee('aria-label="Add Heart Boot to favourites"', false)
        ->assertSee('data-favourited="false"', false);
});

it('uses the bookmark icon, not a heart, in the button and in the header', function (): void {
    $this->actingAs(User::factory()->create(), 'web');
    $product = favouriteProduct();

    $html = $this->get(route('product', $product->defaultUrl->slug))->assertOk()->getContent();

    preg_match('#<button[^>]*data-favourite-button.*?</button>#s', $html, $button);
    preg_match('#<a[^>]*data-favourites-link.*?</a>#s', $html, $link);

    // The bookmark path starts M17.593 3.322; the heart started M12 20.5s.
    expect($button[0] ?? '')->toContain('data-bookmark-icon')->toContain('M17.593 3.322')->not->toContain('M12 20.5s')
        ->and($link[0] ?? '')->toContain('data-bookmark-icon')->toContain('M17.593 3.322')->not->toContain('M12 20.5s');
});

it('saves a product, and removes it on the second press', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    $product = favouriteProduct();

    $this->postJson(route('favourites.toggle', $product->id))
        ->assertOk()
        ->assertExactJson(['favourited' => true, 'count' => 1]);
    expect(Favourite::query()->where('user_id', $user->id)->where('product_id', $product->id)->count())->toBe(1);

    $this->get(route('product', $product->defaultUrl->slug))
        ->assertSee('data-favourited="true"', false)
        ->assertSee('Saved to favourites');

    $this->postJson(route('favourites.toggle', $product->id))
        ->assertOk()
        ->assertExactJson(['favourited' => false, 'count' => 0]);
    expect(Favourite::count())->toBe(0);
});

it('goes back to the page when JavaScript is off', function (): void {
    $this->actingAs(User::factory()->create(), 'web');
    $product = favouriteProduct();

    $this->from(route('shop'))->post(route('favourites.toggle', $product->id))->assertRedirect(route('shop'));

    expect(Favourite::count())->toBe(1);
});

it('keeps favourites per customer', function (): void {
    $product = favouriteProduct();
    $other = User::factory()->create();
    Favourite::create(['user_id' => $other->id, 'product_id' => $product->id]);

    $this->actingAs(User::factory()->create(), 'web');

    $this->get(route('product', $product->defaultUrl->slug))->assertSee('data-favourited="false"', false);
    expect(app(Favourites::class)->count())->toBe(0);
});

it('cannot add a draft product, but can remove one that was saved before it was withdrawn', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    $draft = favouriteProduct('Draft Boot', 'draft');

    $this->postJson(route('favourites.toggle', $draft->id))->assertOk()->assertExactJson(['favourited' => false, 'count' => 0]);
    expect(Favourite::count())->toBe(0);

    Favourite::create(['user_id' => $user->id, 'product_id' => $draft->id]);
    $this->postJson(route('favourites.toggle', $draft->id))->assertOk()->assertExactJson(['favourited' => false, 'count' => 0]);
    expect(Favourite::count())->toBe(0);
});

it('answers 404 for a product that does not exist', function (): void {
    $this->actingAs(User::factory()->create(), 'web');

    $this->postJson(route('favourites.toggle', 999999))->assertNotFound();
});

it('does not let a staff member signed in to Lunar save favourites', function (): void {
    $this->actingAs(Staff::factory()->create(), 'staff');
    $product = favouriteProduct();

    $this->post(route('favourites.toggle', $product->id))->assertRedirect(route('login'));
    expect(Favourite::count())->toBe(0);
});

it('holds one row per customer and product at the database', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    $product = favouriteProduct();
    Favourite::create(['user_id' => $user->id, 'product_id' => $product->id]);

    // The unique key holds even if two requests race past the existence check.
    expect(fn () => Favourite::create(['user_id' => $user->id, 'product_id' => $product->id]))->toThrow(QueryException::class);
    expect(Favourite::count())->toBe(1);
});

it('sends a guest from the favourites page to log in', function (): void {
    $this->get(route('favourites'))->assertRedirect(route('login'));
});

it('lists the saved products, newest first, out of search engines, with the empty state otherwise', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $this->get(route('favourites'))->assertOk()
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertSee('data-favourites-empty', false);

    $first = favouriteProduct('First Boot');
    $second = favouriteProduct('Second Boot');
    $draft = favouriteProduct('Hidden Boot', 'draft');
    foreach ([$first, $second, $draft] as $product) {
        Favourite::create(['user_id' => $user->id, 'product_id' => $product->id]);
    }

    $html = $this->get(route('favourites'))->assertOk()->getContent();

    expect($html)->toContain('data-favourites-list')
        ->and($html)->not->toContain('data-favourites-empty')
        ->and($html)->not->toContain('Hidden Boot')
        ->and(strpos($html, 'Second Boot'))->toBeLessThan(strpos($html, 'First Boot'));
});

it('shows the saved count in the header link on every page', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    $product = favouriteProduct();
    Favourite::create(['user_id' => $user->id, 'product_id' => $product->id]);

    $home = $this->get(route('home'))->assertOk()->getContent();

    expect($home)->toContain('data-favourites-link')
        ->and($home)->toContain('aria-label="Favourites, 1 saved"');
});

it('shows a guest no favourites link in the header', function (): void {
    expect($this->get(route('home'))->getContent())->not->toContain('data-favourites-link');
});

it('deletes a customer\'s favourites when the customer is deleted', function (): void {
    $user = User::factory()->create();
    $product = favouriteProduct();
    Favourite::create(['user_id' => $user->id, 'product_id' => $product->id]);

    $user->delete();

    expect(Favourite::count())->toBe(0);
});
