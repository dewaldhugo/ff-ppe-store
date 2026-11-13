<?php

declare(strict_types=1);

use App\Domain\Pricing\PriceDisplay;
use App\Domain\Pricing\VatDisplayMode;

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

it('shows prices inc VAT by default and marks the active mode', function (): void {
    $html = $this->get('/')->getContent();

    expect($html)->toMatch('#value="inc"\s+aria-pressed="true"#')
        ->and($html)->toMatch('#value="ex"\s+aria-pressed="false"#');
});

it('switches VAT mode from the header toggle and remembers it for the session', function (): void {
    $this->from('/c/safety-footwear')
        ->post('/vat-mode', ['mode' => 'ex'])
        ->assertRedirect('/c/safety-footwear');

    expect(app(PriceDisplay::class)->mode())->toBe(VatDisplayMode::ExVat);

    expect($this->get('/')->getContent())->toMatch('#value="ex"\s+aria-pressed="true"#');

    $this->post('/vat-mode', ['mode' => 'inc']);

    expect(app(PriceDisplay::class)->mode())->toBe(VatDisplayMode::IncVat);
});

it('ignores an invalid VAT mode', function (): void {
    $this->post('/vat-mode', ['mode' => 'free'])->assertRedirect();

    expect(app(PriceDisplay::class)->mode())->toBe(VatDisplayMode::IncVat);
});
