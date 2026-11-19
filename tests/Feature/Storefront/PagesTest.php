<?php

declare(strict_types=1);

use App\Domain\Catalogue\Menu\CategoryMenu;
use App\Models\User;

const NOINDEX = '<meta name="robots" content="noindex">';

beforeEach(function (): void {
    // The flag is the environment's choice; the tests pick their own.
    config()->set('store.noindex', true);
    // Placeholders, so the shell has no tel: or mailto: links whatever this machine's .env says.
    config()->set('store.phone', 'TBC');
    config()->set('store.email', 'TBC');
});

/** Route name => [URI, heading]. Placeholder pages: noindex until they have real content. */
const PLACEHOLDERS = [
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
    [['home', '/'], ['shop', '/shop'], ['register', '/register'], ['login', '/login'], ['account', '/account'], ['cart', '/cart'], ['checkout', '/checkout']],
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
    $this->actingAs(User::factory()->create(), 'web'); // add to cart is for signed-in customers

    $this->get(route('category', 'safety-footwear'))
        ->assertOk()
        ->assertSee('<nav aria-label="Main"', false)
        ->assertSee('data-copyright', false)
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

    $known = collect(['home', 'shop', 'brands', 'about', 'contact', 'login', 'register', 'account', 'cart', 'checkout', 'terms', 'privacy', 'cookies', 'returns', 'delivery', 'accessibility', 'search', 'size-guide', 'technical-features'])
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

it('serves the technical features page at /technical-features in the standard layout', function (): void {
    expect(route('technical-features', [], false))->toBe('/technical-features');

    $html = $this->get(route('technical-features'))->assertOk()->getContent();

    expect($html)->toContain('<title>Technical Features | ')
        ->and($html)->toContain('id="main"')
        ->and($html)->toContain('Technical features</h1>');
});

it('gives the size guide and technical features the same h1 and divider as the other pages', function (): void {
    $placeholder = $this->get(route('about'))->assertOk()->getContent();
    preg_match('#<h1[^>]*class="([^"]*)"#', $placeholder, $standard);

    foreach (['size-guide', 'technical-features'] as $name) {
        $html = $this->get(route($name))->assertOk()->getContent();

        expect(substr_count($html, '<h1'))->toBe(1)
            ->and($html)->toMatch('#<h1[^>]*class="'.preg_quote($standard[1], '#').'"[^>]*>[^<]+</h1>\s*<div aria-hidden="true" class="[^"]*bg-brand-teal#');
    }
});

it('gives the main menu and its products panel the same light grey background', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toMatch('#<nav aria-label="Main"[^>]*bg-surface-nav#')
        ->and($html)->toMatch('#id="products-menu"[^>]*bg-surface-nav#');
});

it('marks the current page in the main menu with brand-blue text and a teal underline, and no other', function (): void {
    $nav = fn (string $route, string|array $params = []): string => (function (string $html): string {
        preg_match('#<nav aria-label="Main".*?</nav>#s', $html, $m);

        return $m[0];
    })($this->get(route($route, $params))->assertOk()->getContent());

    // Products has a button (with JavaScript) and a link (without), so the labels are de-duplicated.
    $current = fn (string $nav): array => array_values(array_unique(array_map(
        fn (array $link): string => trim(strip_tags($link[0])),
        (preg_match_all('#<(?:a|button)[^>]*border-brand-teal[^>]*text-brand-blue[^>]*>.*?</(?:a|button)>#s', $nav, $m, PREG_SET_ORDER) ? $m : []),
    )));

    expect($current($nav('size-guide')))->toBe(['Size Guide'])
        ->and($current($nav('technical-features')))->toBe(['Technical Features'])
        ->and($current($nav('home')))->toBe(['Home'])
        ->and($current($nav('shop')))->toBe(['Products'])
        ->and($current($nav('category', 'safety-footwear')))->toBe(['Products']);
});

it('marks the current page in the mobile menu too', function (): void {
    $html = $this->get(route('technical-features'))->assertOk()->getContent();

    preg_match('#id="mobile-menu".*?</nav>#s', $html, $drawer);

    expect($drawer[0])->toMatch('#<a href="'.preg_quote(route('technical-features'), '#').'" class="[^"]*text-brand-blue[^"]*"\s*aria-current="page"\s*>#')
        ->and(substr_count($drawer[0], 'aria-current="page"'))->toBe(1);
});

it('shows a View product button on every card in the home page picks and in category listings', function (): void {
    preg_match('#<section[^>]*data-home-products.*?</section>#s', $this->get(route('home'))->assertOk()->getContent(), $picks);
    $home = $picks[0] ?? '';
    $listing = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match('#<ul[^>]*data-testid="product-grid".*?</ul>#s', $listing, $grid);

    foreach ([$home, $grid[0]] as $html) {
        $cards = substr_count($html, '<li');

        expect($cards)->toBeGreaterThan(0)
            ->and(substr_count($html, 'View product</span>'))->toBe($cards)
            // Decoration over the stretched card link, so it is not a second link or tab stop.
            ->and($html)->toContain('<span aria-hidden="true" class="inline-flex ');
    }
});

it('uses the standard page heading on category listings', function (): void {
    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match('#<h1[^>]*class="([^"]*)"#', $this->get(route('about'))->getContent(), $standard);

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toMatch('#<h1[^>]*class="'.preg_quote($standard[1], '#').'"[^>]*>Safety Footwear</h1>\s*(?:<!--.*?-->\s*)*<div aria-hidden="true" class="[^"]*bg-brand-teal#s');
});

it('links to technical features straight after the size guide in the desktop and mobile menus', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<header.*?</header>#s', $html, $header);
    preg_match_all('#<a href="([^"]+)"[^>]*>\s*(Size Guide|Technical Features)\s*</a>#', $header[0], $links, PREG_SET_ORDER);

    $hrefs = array_column($links, 1);

    // Desktop nav, then the mobile menu: each lists the size guide first.
    expect($hrefs)->toBe([route('size-guide'), route('technical-features'), route('size-guide'), route('technical-features')]);

    preg_match('#<footer.*?</footer>#s', $html, $footer);

    expect($footer[0])->toMatch('#href="'.preg_quote(route('size-guide'), '#').'"[^>]*>Size Guide</a></li>\s*<li><a href="'.preg_quote(route('technical-features'), '#').'"[^>]*>Technical Features</a>#');
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
