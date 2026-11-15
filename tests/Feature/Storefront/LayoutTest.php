<?php

declare(strict_types=1);

use App\Support\Store;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config()->set('store.name', 'The Bridge Group');
    config()->set('store.phone', 'TBC');
    config()->set('store.email', 'TBC');
});

it('renders a page with the skip link, header, search, main and footer', function (): void {
    $this->get('/products')
        ->assertOk()
        ->assertSee('Skip to main content')
        ->assertSee('<form action="'.route('search').'" method="get" role="search"', false)
        ->assertSee('Search products')
        ->assertSee('<main id="main"', false)
        ->assertSee('<footer', false)
        ->assertSee('lang="en-GB"', false);
});

it('has no VAT toggle, route or session state left, and says that prices are ex VAT', function (): void {
    $html = $this->get('/products')->assertOk()->getContent();

    expect($html)->not->toContain('data-vat-toggle')
        ->and($html)->not->toContain('aria-pressed')
        ->and($html)->not->toContain('inc VAT')
        ->and(Route::has('vat-mode'))->toBeFalse()
        ->and(enum_exists('App\\Domain\\Pricing\\VatDisplayMode'))->toBeFalse();

    $this->post('/vat-mode', ['mode' => 'inc'])->assertNotFound();

    expect(session()->has('vat_display_mode'))->toBeFalse();
});

it('shows the logo in the header, linked to the home page, with the store name as its alt text', function (): void {
    $html = $this->get('/products')->assertOk()->getContent();

    preg_match('#<a href="'.preg_quote(route('home'), '#').'"[^>]*data-site-logo[^>]*>.*?</a>#s', $html, $link);

    expect($link)->not->toBeEmpty()
        ->and($link[0])->toContain('src="'.asset('images/the-bridge-group-logo.svg').'"')
        ->and($link[0])->toContain('alt="The Bridge Group"')
        ->and(is_file(public_path('images/the-bridge-group-logo.svg')))->toBeTrue()
        ->and(is_file(public_path('images/the-bridge-group-logo-white.svg')))->toBeTrue();
});

it('has a top bar with the trade notice, contact details and an account link', function (): void {
    $html = $this->get('/products')->getContent();

    preg_match('#<div class="surface-dark bg-brand-navy.*?</ul>#s', $html, $bar);

    expect($bar[0])->toContain('Trade supplier: all prices exclude VAT')
        ->and($bar[0])->toContain('href="'.route('account').'"')
        // Placeholders are plain text, never a dead tel: or mailto: link.
        ->and($bar[0])->toContain('data-store-phone>TBC<')
        ->and($bar[0])->toContain('data-store-email>TBC<')
        ->and($bar[0])->not->toContain('tel:')
        ->and($bar[0])->not->toContain('mailto:');
});

it('links the phone and email once they are real', function (): void {
    config()->set('store.phone', '020 7946 0000');
    config()->set('store.email', 'trade@example.test');

    $html = $this->get('/products')->getContent();

    expect($html)->toContain('href="tel:02079460000"')
        ->and($html)->toContain('href="mailto:trade@example.test"')
        ->and(Store::phoneHref())->toBe('tel:02079460000');
});

it('has a cart link with an item count badge of zero', function (): void {
    $html = $this->get('/products')->getContent();

    preg_match('#<a\s+href="'.preg_quote(route('cart'), '#').'"[^>]*data-cart-link.*?</a>#s', $html, $cart);

    expect($cart)->not->toBeEmpty()
        ->and($cart[0])->toContain('aria-label="Cart, 0 items"')
        ->and($cart[0])->toMatch('#data-cart-count[^>]*>0<#');
});

it('has the main navigation: Home, Products, About and Contact', function (): void {
    $html = $this->get('/about')->getContent();

    preg_match('#<nav aria-label="Main".*?</nav>#s', $html, $nav);

    expect($nav[0])->toContain('href="'.route('home').'"')
        ->and($nav[0])->toMatch('#href="'.preg_quote(route('about'), '#').'"[^>]*aria-current="page"#')
        ->and($nav[0])->toContain('href="'.route('contact').'"')
        ->and($nav[0])->toContain('data-products-button')
        // The no-JavaScript fallback for the Products button.
        ->and($nav[0])->toContain('href="'.route('products').'"');
});

it('lets a visitor without JavaScript reach the menus as plain links', function (): void {
    $html = $this->get('/products')->getContent();

    // The plain Products link is hidden when JavaScript runs. A display utility
    // beside `hidden` would show it next to the Products button.
    preg_match('#<a[^>]*data-products-link[^>]*>#', $html, $fallback);

    expect($fallback[0])->toContain('class="hidden no-js:inline-block ')
        ->and($fallback[0])->not->toMatch('#\s(?:inline-block|inline-flex|block|flex)\s#')
        ->and($html)->toContain('<html lang="en-GB" class="no-js">')
        ->and($html)->toContain("document.documentElement.classList.remove('no-js')")
        ->and($html)->toContain('no-js:hidden');
});

it('loads Livewire\'s Alpine once on every page, for the menus', function (): void {
    foreach (['/', '/products', '/about'] as $uri) {
        expect(substr_count($this->get($uri)->getContent(), '/livewire/livewire.js'))->toBe(1, $uri);
    }
});
