<?php

declare(strict_types=1);

use App\Domain\Catalogue\Urls\CategoryUrl;
use App\Http\Middleware\RedirectTrailingSlash;
use App\Models\UrlRedirect;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Language;
use Lunar\Models\Url;

function urlCategory(string $name, string $slug, ?Collection $parent = null): Collection
{
    $collection = Collection::create([
        'collection_group_id' => CollectionGroup::query()->where('handle', 'categories')->sole()->id,
        'attribute_data' => ['name' => new TranslatedText(['en' => new Text($name)])],
    ], $parent);

    $collection->urls()->create(['slug' => $slug, 'default' => true, 'language_id' => Language::getDefault()->id]);

    return $collection;
}

it('serves the shop index at its own route and lists the top-level categories', function (): void {
    expect(route('shop', absolute: false))->toBe('/shop');

    $this->get(route('shop'))->assertOk()->assertSee('data-category-grid', false)
        ->assertSee(route('category', 'safety-footwear'), false);
});

it('sends /products to /shop with a 301', function (): void {
    $this->get('/products')->assertStatus(301)->assertRedirect(route('shop'));
});

it('serves products at /products/{slug}', function (): void {
    expect(route('product', 'safety-jogger-ligero', absolute: false))->toBe('/products/safety-jogger-ligero');

    $this->get(route('product', 'safety-jogger-ligero'))->assertOk();
});

it('serves a nested category at its ancestry path, with links up the tree', function (): void {
    expect(route('category', 'safety-footwear/safety-boots', absolute: false))->toBe('/shop/safety-footwear/safety-boots');

    $this->get(route('category', 'safety-footwear/safety-boots'))
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.route('category', 'safety-footwear/safety-boots').'">', false)
        ->assertSee('href="'.route('category', 'safety-footwear').'"', false);
});

it('answers 404 for a path that names no category', function (string $path): void {
    $this->get(route('category', $path))->assertNotFound();
})->with(['not-a-category', 'safety-footwear/not-a-category', 'safety-footwear/safety-boots/extra']);

it('answers 404 for a category outside the categories group', function (): void {
    $range = Collection::create([
        'collection_group_id' => CollectionGroup::query()->where('handle', 'ranges')->sole()->id,
        'attribute_data' => ['name' => new TranslatedText(['en' => new Text('Spring Offers')])],
    ]);
    $range->urls()->create(['slug' => 'spring-offers', 'default' => true, 'language_id' => Language::getDefault()->id]);

    $this->get(route('category', 'spring-offers'))->assertNotFound();
});

it('301s a resolvable but wrong ancestry to the correct path', function (): void {
    $this->get(route('category', 'wellingtons/safety-boots'))
        ->assertStatus(301)
        ->assertRedirect(route('category', 'safety-footwear/safety-boots'));

    // A bare child slug is not a path either.
    $this->get(route('category', 'safety-boots'))
        ->assertStatus(301)
        ->assertRedirect(route('category', 'safety-footwear/safety-boots'));
});

it('keeps the query string on a redirect', function (): void {
    $this->get(route('category', 'wellingtons/safety-boots').'?sort=price-asc')
        ->assertStatus(301)
        ->assertRedirect(route('category', 'safety-footwear/safety-boots').'?sort=price-asc');
});

it('resolves by the full path when two parents have a child with the same slug', function (): void {
    $first = urlCategory('First Parent', 'first-parent');
    $second = urlCategory('Second Parent', 'second-parent');
    urlCategory('Gloves One', 'gloves', $first);
    urlCategory('Gloves Two', 'gloves', $second);

    $heading = fn (string $path): string => (preg_match('#<h1[^>]*>\s*(.*?)\s*</h1>#s', $this->get(route('category', $path))->assertOk()->getContent(), $m) ? $m[1] : '');

    expect($heading('first-parent/gloves'))->toBe('Gloves One')
        ->and($heading('second-parent/gloves'))->toBe('Gloves Two');

    // The last segment alone is ambiguous, so it is never guessed.
    $this->get(route('category', 'gloves'))->assertNotFound();
    $this->get(route('category', 'first-parent/second-parent/gloves'))->assertNotFound();
});

it('301s a category path that differs only by case to the lowercase path', function (): void {
    $this->get('/shop/Safety-Footwear/safety-boots')
        ->assertStatus(301)
        ->assertRedirect(route('category', 'safety-footwear/safety-boots'));
});

it('301s a product slug that differs only by case to the lowercase slug', function (): void {
    $this->get('/products/Safety-Jogger-Ligero?colour=black')
        ->assertStatus(301)
        ->assertRedirect(route('product', 'safety-jogger-ligero').'?colour=black');
});

it('answers 404 for an unknown product slug', function (): void {
    $this->get(route('product', 'does-not-exist'))->assertNotFound();
});

/**
 * A GET with the URL exactly as given. $this->get() trims a trailing slash
 * before sending, so it cannot test one.
 */
function getUntrimmed(string $url): TestResponse
{
    $request = Request::create($url, 'GET');

    return TestResponse::fromBaseResponse(
        app(Kernel::class)->handle($request),
        $request,
    );
}

it('301s a trailing slash to the same URL without it, keeping the query string', function (array $route, string $query): void {
    $url = route(...$route);

    getUntrimmed($url.'/'.$query)->assertStatus(301)->assertRedirect($url.$query);
})->with([
    [['shop'], ''],
    [['category', 'safety-footwear'], ''],
    [['category', 'safety-footwear/safety-boots'], '?sort=price-asc'],
    [['product', 'safety-jogger-ligero'], ''],
    [['about'], ''],
]);

it('301s /products/ to /products, which then goes to /shop', function (): void {
    // url() trims a trailing slash itself, so the slash is added by hand.
    getUntrimmed(url('').'/products/')->assertStatus(301)->assertRedirect(url('/products'));
});

it('leaves the home page and non-GET requests alone', function (): void {
    $this->get('/')->assertOk();

    $middleware = new RedirectTrailingSlash;
    $response = $middleware->handle(Request::create('/shop/', 'POST'), fn () => response('passed'));

    expect($response->getContent())->toBe('passed');
});

it('applies the trailing slash redirect to storefront routes only', function (): void {
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $guarded = in_array(RedirectTrailingSlash::class, $route->gatherMiddleware(), true);

        if (str_starts_with($uri, 'livewire') || str_starts_with($uri, 'lunar') || str_starts_with($uri, 'horizon') || str_starts_with($uri, 'api')) {
            expect($guarded)->toBeFalse($uri);
        }
    }

    expect(in_array(RedirectTrailingSlash::class, Route::getRoutes()->getByName('category')->gatherMiddleware(), true))->toBeTrue();
});

describe('redirects after a category changes', function (): void {
    it('301s the old path of a renamed category to the new one', function (): void {
        $category = urlCategory('Test Visibility', 'test-before');

        $category->defaultUrl->update(['slug' => 'test-after']);

        $this->get(route('category', 'test-after'))->assertOk();
        $this->get(route('category', 'test-before').'?sort=price-asc')
            ->assertStatus(301)
            ->assertRedirect(route('category', 'test-after').'?sort=price-asc');
    });

    it('301s the old path of a moved category, and of its children', function (): void {
        $parent = urlCategory('Moving Parent', 'moving-parent');
        urlCategory('Moving Child', 'moving-child', $parent);
        $home = urlCategory('New Home', 'new-home');

        $parent->appendToNode($home)->save();

        $this->get(route('category', 'new-home/moving-parent/moving-child'))->assertOk();

        $this->get(route('category', 'moving-parent'))
            ->assertStatus(301)
            ->assertRedirect(route('category', 'new-home/moving-parent'));

        $this->get(route('category', 'moving-parent/moving-child'))
            ->assertStatus(301)
            ->assertRedirect(route('category', 'new-home/moving-parent/moving-child'));
    });

    it('sends an old path straight to the final one after two renames', function (): void {
        $category = urlCategory('Chain', 'chain-a');

        $category->defaultUrl->update(['slug' => 'chain-b']);
        $category->defaultUrl->update(['slug' => 'chain-c']);

        $this->get(route('category', 'chain-a'))->assertStatus(301)->assertRedirect(route('category', 'chain-c'));
        $this->get(route('category', 'chain-b'))->assertStatus(301)->assertRedirect(route('category', 'chain-c'));
    });

    it('answers 404 rather than looping on a damaged redirect table', function (): void {
        UrlRedirect::query()->create(['old_path' => '/shop/loop-a', 'new_path' => '/shop/loop-b']);
        UrlRedirect::query()->create(['old_path' => '/shop/loop-b', 'new_path' => '/shop/loop-a']);

        $this->get(route('category', 'loop-a'))->assertNotFound();
    });

    it('keeps the menu links in step with the rename', function (): void {
        $before = app(CategoryUrl::class)->forHandle('wellingtons');

        $category = Url::query()->where('slug', 'wellingtons')->sole();
        $category->update(['slug' => 'wellies']);

        $after = app(CategoryUrl::class)->forHandle('wellingtons');

        expect($after)->toEndWith('/wellies')->not->toBe($before);

        $this->get(route('shop'))
            ->assertSee($after, false)
            ->assertDontSee('href="'.$before.'"', false);
    });
});
