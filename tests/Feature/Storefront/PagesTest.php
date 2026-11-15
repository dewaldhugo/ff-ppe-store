<?php

declare(strict_types=1);

use App\Domain\Catalogue\Menu\CategoryMenu;

const NOINDEX = '<meta name="robots" content="noindex">';

beforeEach(function (): void {
    // The flag is the environment's choice; the tests pick their own.
    config()->set('store.noindex', true);
});

/** Route name => [URI, heading]. Placeholder pages: noindex until they have real content. */
const PLACEHOLDERS = [
    'about' => ['/about', 'About us'],
    'contact' => ['/contact', 'Contact us'],
    'account' => ['/account', 'Account'],
    'cart' => ['/cart', 'Cart'],
    'checkout' => ['/checkout', 'Checkout'],
    'terms' => ['/terms', 'Terms and Conditions of Sale'],
    'privacy' => ['/privacy', 'Privacy Policy'],
    'cookies' => ['/cookies', 'Cookie Policy'],
    'returns' => ['/returns', 'Returns and Refunds'],
    'delivery' => ['/delivery', 'Delivery Information'],
    'accessibility' => ['/accessibility', 'Accessibility Statement'],
];

it('has a named route for every page', function (string $name, string $uri): void {
    expect(route($name, absolute: false))->toBe($uri);
})->with(array_merge(
    [['home', '/'], ['products', '/products']],
    array_map(fn (string $name, array $page): array => [$name, $page[0]], array_keys(PLACEHOLDERS), PLACEHOLDERS),
));

it('renders each placeholder page with its heading, the standard layout and noindex', function (string $name, string $uri, string $heading): void {
    $response = $this->get($uri)->assertOk();
    $html = $response->getContent();

    $response->assertSee(NOINDEX, false)
        ->assertSee('<h1', false)
        ->assertSee($heading)
        ->assertSee('Content coming soon.')
        ->assertSee('<title>'.$heading.' | ', false)
        ->assertSee('<footer', false)
        ->assertSee('<nav aria-label="Main"', false);

    expect(substr_count($html, '<h1'))->toBe(1);
})->with(array_map(fn (string $name, array $page): array => [$name, $page[0], $page[1]], array_keys(PLACEHOLDERS), PLACEHOLDERS));

it('keeps the home page out of search engines, with a hero and an outline button to Products', function (): void {
    $html = $this->get('/')->assertOk()->assertSee(NOINDEX, false)->getContent();

    expect($html)->toContain('bg-brand-navy text-white')
        ->and($html)->toContain('<h1')
        ->and($html)->toMatch('#<a href="'.preg_quote(route('products'), '#').'"[^>]*border-brand-teal[^>]*>\s*Shop products\s*</a>#');
});

it('uses the site photo as the home hero background, under the brand-navy overlay', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    preg_match('#<section[^>]*surface-dark.*?</section>#s', $html, $hero);

    expect(is_file(public_path('images/home-hero.jpg')))->toBeTrue()
        // A decorative photo: empty alt text, dimensions set, behind the overlay.
        ->and($hero[0])->toContain('src="'.asset('images/home-hero.jpg').'"')
        ->and($hero[0])->toContain('alt=""')
        ->and($hero[0])->toContain('width="1529"')
        ->and($hero[0])->toContain('height="750"')
        ->and($hero[0])->toContain('-z-20')
        // Cropped from the top, so the heads stay in frame.
        ->and($hero[0])->toContain('object-cover object-top')
        // The overlay keeps white text readable on any part of the photo.
        ->and($hero[0])->toMatch('#data-hero-overlay[^>]*-z-10[^>]*from-brand-navy/90#')
        ->and($hero[0])->toContain('Personal protective equipment for business');
});

it('shows a grid of the top-level categories on the home page, each linking to its listing', function (): void {
    $html = $this->get('/')->getContent();

    preg_match('#<ul[^>]*data-category-grid.*?</ul>#s', $html, $grid);

    $top = collect(app(CategoryMenu::class)->tree());

    expect($top->count())->toBeGreaterThanOrEqual(7);

    foreach ($top as $category) {
        expect($grid[0])->toContain('href="'.$category['url'].'"')
            ->and($grid[0])->toContain($category['name']);
    }

    // Nothing invented: no testimonials, client logos or statistics on a placeholder.
    expect(strtolower($html))->not->toContain('testimonial')
        ->and(strtolower($html))->not->toContain('trusted by');
});

it('keeps every page out of search engines while STORE_NOINDEX is true', function (string $uri): void {
    $this->get($uri)->assertOk()->assertSee(NOINDEX, false);
})->with(['/', '/products', '/c/safety-footwear', '/p/safety-jogger-ligero', '/search?q=boots', '/about', '/privacy']);

it('lets search engines index the real pages once STORE_NOINDEX is false', function (string $uri): void {
    config()->set('store.noindex', false);

    $this->get($uri)->assertOk()->assertDontSee(NOINDEX, false);
})->with(['/', '/products', '/c/safety-footwear', '/p/safety-jogger-ligero', '/search?q=boots']);

it('keeps a placeholder page out of search engines until it has content, even after launch', function (): void {
    config()->set('store.noindex', false);

    $this->get('/privacy')->assertOk()->assertSee(NOINDEX, false);
});

it('turns noindex on from a single flag that defaults to on', function (): void {
    expect(config('store.noindex'))->toBeTrue()
        ->and((string) file_get_contents(config_path('store.php')))->toContain("env('STORE_NOINDEX', true)")
        ->and((string) file_get_contents(base_path('.env.example')))->toContain('STORE_NOINDEX=true')
        // The flag, not a page, owns the decision: no view sets noindex for the home page.
        ->and((string) file_get_contents(resource_path('views/home.blade.php')))->not->toContain('noindex');
});

it('has a Launch checklist in CLAUDE.md with the three launch tasks', function (): void {
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));

    expect($claude)->toContain('## Launch checklist')
        ->and($claude)->toContain('Lift noindex')
        ->and($claude)->toContain('chip returns products on real data')
        ->and($claude)->toContain('Confirm the trust strip claims with the client');
});

it('renders the existing listing and product pages inside the new layout', function (): void {
    $this->get('/c/safety-footwear')
        ->assertOk()
        ->assertSee('<nav aria-label="Main"', false)
        ->assertSee('data-business-notice', false)
        ->assertSee('data-testid="product-grid"', false);

    $this->get('/p/safety-jogger-ligero')
        ->assertOk()
        ->assertSee('<footer', false)
        ->assertSee('data-gallery-main', false)
        ->assertSee('data-add-to-cart', false);
});

it('uses route names for every link in the header and footer', function (): void {
    $html = $this->get('/products')->getContent();

    preg_match_all('#<(?:header|footer).*?</(?:header|footer)>#s', $html, $shell);
    preg_match_all('~href="([^"#]+)"~', implode('', $shell[0]), $hrefs);

    $known = collect(['home', 'products', 'about', 'contact', 'account', 'cart', 'checkout', 'terms', 'privacy', 'cookies', 'returns', 'delivery', 'accessibility', 'search'])
        ->map(fn (string $name): string => route($name));

    $categories = collect(app(CategoryMenu::class)->tree())
        ->flatMap(fn (array $category): array => [$category['url'], ...array_column($category['children'], 'url')]);

    foreach (array_unique($hrefs[1]) as $href) {
        expect($known->merge($categories)->contains($href))->toBeTrue("Unexpected link in the shell: {$href}");
    }
});
