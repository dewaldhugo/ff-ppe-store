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
    [['home', '/'], ['shop', '/shop']],
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

it('keeps the home page out of search engines while STORE_NOINDEX is true', function (): void {
    $this->get(route('home'))->assertOk()->assertSee(NOINDEX, false);
});

it('keeps every page out of search engines while STORE_NOINDEX is true', function (array $route): void {
    $this->get(route(...$route))->assertOk()->assertSee(NOINDEX, false);
})->with([
    [['home']], [['shop']], [['category', 'safety-footwear']], [['product', 'safety-jogger-ligero']],
    [['search', ['q' => 'boots']]], [['about']], [['privacy']],
]);

it('lets search engines index the real pages once STORE_NOINDEX is false', function (array $route): void {
    config()->set('store.noindex', false);

    $this->get(route(...$route))->assertOk()->assertDontSee(NOINDEX, false);
})->with([
    [['home']], [['shop']], [['category', 'safety-footwear']], [['product', 'safety-jogger-ligero']],
    [['search', ['q' => 'boots']]],
]);

it('keeps a placeholder page out of search engines until it has content, even after launch', function (): void {
    config()->set('store.noindex', false);

    $this->get(route('privacy'))->assertOk()->assertSee(NOINDEX, false);
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
    $this->get(route('category', 'safety-footwear'))
        ->assertOk()
        ->assertSee('<nav aria-label="Main"', false)
        ->assertSee('data-business-notice', false)
        ->assertSee('data-testid="product-grid"', false);

    $this->get(route('product', 'safety-jogger-ligero'))
        ->assertOk()
        ->assertSee('<footer', false)
        ->assertSee('data-gallery-main', false)
        ->assertSee('data-add-to-cart', false);
});

it('uses route names for every link in the header and footer', function (): void {
    $html = $this->get(route('shop'))->getContent();

    preg_match_all('#<(?:header|footer).*?</(?:header|footer)>#s', $html, $shell);
    preg_match_all('~href="([^"#]+)"~', implode('', $shell[0]), $hrefs);

    $known = collect(['home', 'shop', 'about', 'contact', 'account', 'cart', 'checkout', 'terms', 'privacy', 'cookies', 'returns', 'delivery', 'accessibility', 'search', 'size-guide'])
        ->map(fn (string $name): string => route($name));

    $categories = collect(app(CategoryMenu::class)->tree())
        ->flatMap(fn (array $category): array => [$category['url'], ...array_column($category['children'], 'url')]);

    foreach (array_unique($hrefs[1]) as $href) {
        expect($known->merge($categories)->contains($href))->toBeTrue("Unexpected link in the shell: {$href}");
    }
});

it('serves the size guide at /size-guide in the standard layout', function (): void {
    expect(route('size-guide', [], false))->toBe('/size-guide');

    $html = $this->get(route('size-guide'))->assertOk()->getContent();

    expect($html)->toContain('Size Guide')
        ->and($html)->toContain('<title>Size Guide | ')
        ->and($html)->toContain('id="main"');
});

it('links to the size guide from the menus and the footer', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<nav aria-label="Main".*?</nav>#s', $html, $nav);
    preg_match('#<footer.*?</footer>#s', $html, $footer);

    expect($nav[0])->toContain('href="'.route('size-guide').'"')
        ->and($footer[0])->toContain('href="'.route('size-guide').'"')
        ->and($html)->toContain('>Size Guide<');
});

it('gives every table on the footwear and workwear tabs the same column widths', function (): void {
    $html = $this->get(route('size-guide'))->assertOk()->getContent();

    foreach (['panel-shoes' => 'panel-workwear', 'panel-workwear' => 'panel-gloves'] as $from => $to) {
        $panel = substr($html, (int) strpos($html, 'id="'.$from.'"'), (int) strpos($html, 'id="'.$to.'"') - (int) strpos($html, 'id="'.$from.'"'));

        preg_match_all('#<colgroup><col style="width: ([\d.]+)rem"><col span="\d+" style="width: ([\d.]+)rem"></colgroup>#', $panel, $groups, PREG_SET_ORDER);

        expect($groups)->toHaveCount(substr_count($panel, '<table'))
            ->and(array_unique(array_column($groups, 1)))->toHaveCount(1)
            ->and(array_unique(array_column($groups, 2)))->toHaveCount(1);
    }
});

it('shows the glove diagram beside the glove table', function (): void {
    $html = $this->get(route('size-guide'))->assertOk()->getContent();

    expect($html)->toContain('alt="Glove diagram:')
        ->and($html)->toContain('lg:items-end');
});
