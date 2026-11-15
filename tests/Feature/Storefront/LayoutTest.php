<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('renders the home page with header, search and footer', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Skip to main content')
        ->assertSee('<form action="'.route('search').'" method="get" role="search"', false)
        ->assertSee('Search products')
        ->assertSee('<main id="main"', false)
        ->assertSee('<footer', false)
        ->assertSee('lang="en-GB"', false);
});

it('builds the category navigation from the categories collection group', function (): void {
    $response = $this->get('/')->assertOk()->assertSee('<nav aria-label="Categories"', false);

    foreach (['safety-footwear', 'safety-trainers', 'safety-shoes', 'safety-boots', 'occupational-footwear', 'slippers'] as $slug) {
        $response->assertSee('href="'.route('category', $slug).'"', false);
    }

    // Ranges are not categories.
    $response->assertDontSee('href="'.route('category', 'clearance').'"', false);
});

it('has no VAT toggle and says that prices are ex VAT', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('data-vat-toggle')
        ->and($html)->not->toContain('aria-pressed')
        ->and($html)->not->toContain('inc VAT')
        ->and($html)->toContain('All prices are shown ex VAT.');
});

it('no longer has a route to switch VAT mode', function (): void {
    expect(Route::has('vat-mode'))->toBeFalse();

    $this->post('/vat-mode', ['mode' => 'inc'])->assertNotFound();
});

it('shows the logo in the header, linked to the home page', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    preg_match('#<a href="'.preg_quote(route('home'), '#').'"[^>]*data-site-logo[^>]*>.*?</a>#s', $html, $link);

    expect($link)->not->toBeEmpty()
        ->and($link[0])->toContain('src="'.asset('images/the-bridge-group-logo.svg').'"')
        // The image is the only content of the link, so its alt text names the site.
        ->and($link[0])->toContain('alt="'.e(config('app.name')).'"')
        ->and(is_file(public_path('images/the-bridge-group-logo.svg')))->toBeTrue();
});
