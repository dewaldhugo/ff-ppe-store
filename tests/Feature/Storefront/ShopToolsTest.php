<?php

declare(strict_types=1);

use App\Domain\Cart\CartCrossSell;
use App\Domain\Catalogue\Recent\RecentlyViewed;
use App\Livewire\Quote\RequestForm;
use App\Mail\QuoteRequested;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Product;
use Tests\Support\SearchIndex;

// Recently viewed, search suggestions, "You may also need" in the cart and Request a quote.

beforeEach(function (): void {
    config()->set('store.noindex', true);
    config()->set('store.order_notification_emails', 'orders@example.test');
    RateLimiter::clear('quote:127.0.0.1');
    Mail::fake();
    SearchIndex::use();
});

function toolProductIds(int $count = 3): array
{
    return Product::query()->where('status', 'published')->orderBy('id')->limit($count)->pluck('id')->all();
}

// --- Recently viewed -------------------------------------------------------------------------------

it('records the products a visitor opens, newest first, and not the same one twice', function (): void {
    $this->get(route('product', 'safety-jogger-ligero'))->assertOk();
    $this->get(route('product', 'gbs-eva'))->assertOk();
    $this->get(route('product', 'safety-jogger-ligero'))->assertOk();

    $ids = app(RecentlyViewed::class)->ids();

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toBe(Product::query()->whereHas('urls', fn ($q) => $q->where('slug', 'safety-jogger-ligero'))->value('id'));
});

it('keeps at most eight', function (): void {
    $recent = new RecentlyViewed;

    foreach (range(1, 12) as $id) {
        $recent->record($id);
    }

    expect($recent->ids())->toBe([12, 11, 10, 9, 8, 7, 6, 5]);
});

it('shows the other products opened, not the one being looked at, and nothing on a first visit', function (): void {
    $first = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    expect($first)->not->toContain('data-recently-viewed');

    $second = $this->get(route('product', 'gbs-eva'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-recently-viewed.*?</section>#s', $second, $strip);

    expect($strip[0] ?? '')->toContain('Recently viewed')->toContain(route('product', 'safety-jogger-ligero'))->not->toContain('href="'.route('product', 'gbs-eva').'"');
});

it('shows the strip on the home page once something has been viewed', function (): void {
    $this->get(route('home'))->assertOk()->assertDontSee('data-home-recent', false);

    $this->get(route('product', 'safety-jogger-ligero'))->assertOk();

    $html = $this->get(route('home'))->assertOk()->assertSee('data-home-recent', false)->assertSee('Recently viewed')->getContent();

    // Below the testimonials and above the service strip.
    expect(strpos($html, 'data-home-testimonials'))->toBeLessThan(strpos($html, 'data-home-recent'))
        ->and(strpos($html, 'data-home-recent'))->toBeLessThan(strpos($html, 'data-home-service'));
});

// --- Search suggestions ----------------------------------------------------------------------------

it('suggests products for what has been typed, as JSON, with a link to all the results', function (): void {
    $response = $this->getJson(route('search.suggest', ['q' => 'ligero']))->assertOk();

    expect($response->json('products'))->not->toBeEmpty()->and($response->json('products.0'))->toHaveKeys(['name', 'brand', 'url', 'image'])
        ->and($response->json('all.url'))->toBe(route('search', ['q' => 'ligero']))
        ->and($response->json('all.total'))->toBeGreaterThan(0)
        ->and(count($response->json('products')))->toBeLessThanOrEqual(6);
});

it('suggests nothing for less than two letters', function (): void {
    $this->getJson(route('search.suggest', ['q' => 'l']))->assertOk()->assertExactJson(['products' => [], 'all' => null]);
    $this->getJson(route('search.suggest'))->assertOk()->assertExactJson(['products' => [], 'all' => null]);
});

it('limits how often the suggestions can be asked for', function (): void {
    foreach (range(1, 60) as $i) {
        $this->getJson(route('search.suggest', ['q' => 'x']))->assertOk();
    }

    $this->getJson(route('search.suggest', ['q' => 'x']))->assertStatus(429);
});

it('has the type-ahead on the header search box, which still submits to /search', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<form[^>]*role="search".*?</form>#s', $html, $form);

    expect($form[0] ?? '')->toContain('action="'.route('search').'"')->toContain('role="combobox"')->toContain('autocomplete="off"')
        ->toContain('data-search-suggestions')->toContain('role="listbox"')->toContain(route('search.suggest'))
        ->toContain('name="q"')->toContain('type="submit"');
});

// --- You may also need (cart) ----------------------------------------------------------------------

it('suggests what goes with the cart, never what is already in it', function (): void {
    $user = User::factory()->create();
    $variant = Product::query()->whereHas('urls', fn ($q) => $q->where('slug', 'safety-jogger-ligero'))->firstOrFail()->variants()->firstOrFail();

    $cart = Cart::query()->create(['currency_id' => Currency::getDefault()->id, 'channel_id' => Channel::getDefault()->id, 'user_id' => $user->id]);
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);

    $products = app(CartCrossSell::class)->for($cart->fresh('lines.purchasable'));

    expect($products->count())->toBeLessThanOrEqual(CartCrossSell::LIMIT)
        ->and($products->pluck('id')->all())->not->toContain($variant->product_id);
});

it('suggests nothing for an empty cart', function (): void {
    $cart = Cart::query()->create(['currency_id' => Currency::getDefault()->id, 'channel_id' => Channel::getDefault()->id]);

    expect(app(CartCrossSell::class)->for($cart->fresh('lines')))->toBeEmpty();
});

// --- Request a quote -------------------------------------------------------------------------------

it('has the quote page and links to it from the product page', function (): void {
    expect(route('quote', absolute: false))->toBe('/request-a-quote');

    $this->get(route('quote'))->assertOk()->assertSee('Request a quote')->assertSee('data-quote-form', false)->assertSee('<meta name="robots" content="noindex">', false);

    $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->assertSee('data-quote-link', false);
});

it('sends a quote request for a product to the store, with a reply to the customer', function (): void {
    [$id] = toolProductIds(1);
    $name = (string) Product::query()->findOrFail($id)->translateAttribute('name');

    $this->get(route('quote', ['product' => $id]));

    Livewire::withQueryParams(['product' => $id])->test(RequestForm::class)
        ->assertSet('items', [['name' => $name, 'detail' => null, 'quantity' => null]])
        ->set('name', 'Sam Jones')->set('email', 'sam@example.test')->set('company', 'Builder Ltd')->set('quantity', '50 pairs, sizes 7 to 11')
        ->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    Mail::assertSent(QuoteRequested::class, function (QuoteRequested $mail) use ($name): bool {
        return $mail->hasTo('orders@example.test') && $mail->hasReplyTo('sam@example.test')
            && str_contains($mail->render(), 'Company: Builder Ltd') && str_contains($mail->render(), $name) && str_contains($mail->render(), '50 pairs');
    });
});

it('needs a message when the quote is not for a product or the cart', function (): void {
    Livewire::test(RequestForm::class)->set('name', 'Sam')->set('email', 'sam@example.test')->call('submit')->assertHasErrors('body');

    Mail::assertNothingSent();

    Livewire::test(RequestForm::class)->set('name', 'Sam')->set('email', 'sam@example.test')->set('body', 'Forty pairs of S3 boots for a site team.')->call('submit')->assertHasNoErrors();

    Mail::assertSent(QuoteRequested::class, 1);
});

it('checks the name and the email address', function (): void {
    Livewire::test(RequestForm::class)->set('name', '')->set('email', 'not-an-email')->set('body', 'Forty pairs of S3 boots.')
        ->call('submit')->assertHasErrors(['name', 'email']);

    Mail::assertNothingSent();
});

it('sends nothing, and says so as if it worked, when the hidden field is filled', function (): void {
    Livewire::test(RequestForm::class)->set('name', 'Bot')->set('email', 'bot@example.test')->set('body', 'Buy cheap pills from us today.')->set('website', 'http://spam.test')
        ->call('submit')->assertSet('sent', true);

    Mail::assertNothingSent();
});

it('limits the requests one address can send', function (): void {
    foreach (range(1, 5) as $i) {
        Livewire::test(RequestForm::class)->set('name', 'Sam')->set('email', 'sam@example.test')->set('body', 'Forty pairs of S3 boots for a team.')->call('submit')->assertHasNoErrors();
    }

    Livewire::test(RequestForm::class)->set('name', 'Sam')->set('email', 'sam@example.test')->set('body', 'Forty pairs of S3 boots for a team.')->call('submit')->assertHasErrors('form');

    Mail::assertSent(QuoteRequested::class, 5);
});

it('uses UK English and no em dashes in its copy', function (): void {
    foreach (['views/pages/request-quote.blade.php', 'views/livewire/quote/request-form.blade.php', 'views/mail/quote-requested.blade.php', 'views/components/storefront/recently-viewed.blade.php'] as $file) {
        $text = strip_tags((string) file_get_contents(resource_path($file)));

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(color|center|organization|program)\b/i');
    }
});
