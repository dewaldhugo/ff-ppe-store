<?php

declare(strict_types=1);
use App\Models\User;
use App\Support\GuideIcons;

// The Size Guide and Technical Features links are two links with an icon each beside the search box
// (resources/views/components/storefront/header.blade.php), not items of the main menu bar and not a drop down, and the search box
// takes the room between the logo and them, so they end at the right of the container.

/**
 * The two Tools links beside the search, as the markup of each.
 *
 * @return list<string>
 */
function toolLinks(string $html): array
{
    preg_match('#<ul[^>]*data-tools-menu.*?</ul>#s', $html, $list);
    preg_match_all('#<a\s[^>]*data-tool-link.*?</a>#s', $list[0] ?? '', $links);

    return $links[0];
}

it('puts the two Tools links beside the search, after it and before the favourites and cart', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    $search = strpos($html, 'role="search"');
    $tools = strpos($html, 'data-tools-menu');

    expect($search)->not->toBeFalse()->and($tools)->not->toBeFalse()
        ->and($tools)->toBeGreaterThan($search)
        ->and(substr($html, (int) $search, (int) $tools - (int) $search))->not->toContain('data-cart-link');
});

it('shows the size guide and the technical features as links, not a drop down', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    $links = toolLinks($html);

    expect($links)->toHaveCount(2)
        ->and($links[0])->toContain('href="'.route('size-guide').'"')->toContain('Size Guide')
        ->and($links[1])->toContain('href="'.route('technical-features').'"')->toContain('Technical Features')
        ->and($html)->not->toContain('data-tools-button')->not->toContain('data-tools-list')
        ->and($html)->not->toContain('<details');
});

it('gives each link its own icon', function (): void {
    $links = toolLinks($this->get(route('about'))->assertOk()->getContent());

    expect($links[0])->toContain('data-tool-icon="size-guide"')
        ->and($links[1])->toContain('data-tool-icon="technical-features"')
        // Decoration: hidden from a screen reader, which reads the name.
        ->and($links[0])->toContain('<svg aria-hidden="true"')
        ->and($links[1])->toContain('<svg aria-hidden="true"')
        // The two icons are different pictures.
        ->and(preg_replace('/\s+/', '', $links[0]))->not->toBe(preg_replace('/\s+/', '', $links[1]));
});

it('shows the words from the xl breakpoint, and the icon alone below it with the name for a screen reader and a tooltip', function (): void {
    $links = toolLinks($this->get(route('about'))->assertOk()->getContent());

    foreach ([['Size Guide', 0], ['Technical Features', 1]] as [$label, $index]) {
        expect($links[$index])->toContain('title="'.$label.'"')
            ->and($links[$index])->toMatch('#<span class="sr-only xl:not-sr-only">'.$label.'</span>#');
    }
});

it('is for large screens only, the mobile menu having the same links', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<ul[^>]*data-tools-menu[^>]*>#', $html, $list);

    expect($list[0])->toContain('hidden')->toContain('lg:flex');
});

it('takes the size guide and technical features out of the main menu bar', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<nav aria-label="Main".*?</nav>#s', $html, $nav);

    expect($nav[0])->not->toContain('Size Guide')->not->toContain('Technical Features')
        // The rest of the bar is as it was.
        ->and($nav[0])->toContain('>Brands<')->toContain('>Wear Trials<')->toContain('>PPE Health Check<')->toContain('>About<')->toContain('>Contact<');
});

it('marks the link of the page you are on as current, and only that one', function (): void {
    foreach (['size-guide' => 0, 'technical-features' => 1] as $name => $index) {
        $links = toolLinks($this->get(route($name))->assertOk()->getContent());

        expect($links[$index])->toContain('aria-current="page"')->toMatch('/(?<![:\w-])bg-surface-muted/')
            ->and($links[1 - $index])->not->toContain('aria-current')->not->toMatch('/(?<![:\w-])bg-surface-muted/');
    }

    foreach (toolLinks($this->get(route('about'))->assertOk()->getContent()) as $link) {
        expect($link)->not->toContain('aria-current')->not->toMatch('/(?<![:\w-])bg-surface-muted/');
    }
});

it('keeps the Tools links in the mobile menu, in a group of their own, with the same icons', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#id="mobile-menu".*?</nav>#s', $html, $drawer);
    preg_match('#<ul[^>]*data-drawer-tools.*?</ul>#s', $drawer[0], $tools);

    expect($drawer[0])->toContain('>Tools</span>')
        ->and($tools[0])->toContain('href="'.route('size-guide').'"')->toContain('href="'.route('technical-features').'"')
        ->and($tools[0])->toContain('data-tool-icon="size-guide"')->toContain('data-tool-icon="technical-features"')
        // Each is in the Tools group only.
        ->and(substr_count($drawer[0], 'href="'.route('size-guide').'"'))->toBe(1)
        ->and(substr_count($drawer[0], 'href="'.route('technical-features').'"'))->toBe(1);
});

it('lets the search box take the room between the logo and the Tools links, so they end at the right of the container', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<form[^>]*role="search"[^>]*>#', $html, $form);

    // It grows to fill the row (flex-1) with no maximum width, and is the full width on a phone, on a row of its own.
    expect($form[0])->toContain('lg:flex-1')->toContain('w-full')->not->toContain('max-w');
});

it('keeps the favourites and cart at the right of the bar', function (): void {
    $this->actingAs(User::factory()->create(), 'web');

    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<a[^>]*data-favourites-link[^>]*>#', $html, $favourites);

    expect($favourites[0])->toContain('ml-auto')->not->toContain('lg:ml-0');
});

it('uses the buying guide cards\' icons for the same two pages beside the search: a ruler and a shield', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<ul[^>]*data-tools-menu.*?</ul>#s', $html, $tools);
    preg_match_all('#<svg[^>]*data-tool-icon="([^"]+)"[^>]*>(.*?)</svg>#s', $tools[0], $header, PREG_SET_ORDER);
    preg_match('#<section[^>]*data-home-guides.*?</section>#s', $html, $guides);
    preg_match_all('#<span[^>]*data-guide-icon>\s*<svg[^>]*>(.*?)</svg>#s', $guides[0], $cards);

    $paths = fn (string $inside): array => array_map(fn (string $d): string => html_entity_decode($d), (preg_match_all('#<path d="([^"]+)"#', $inside, $found) ? $found[1] : []));

    expect($header)->toHaveCount(2)
        ->and($cards[1])->toHaveCount(2)
        // The size guide: the card's ruler. The technical features: the card's shield.
        ->and($header[0][1])->toBe('size-guide')->and($paths($header[0][2]))->toBe($paths($cards[1][0]))->and($paths($header[0][2]))->toBe(GuideIcons::PATHS['ruler'])
        ->and($header[1][1])->toBe('technical-features')->and($paths($header[1][2]))->toBe($paths($cards[1][1]))->and($paths($header[1][2]))->toBe(GuideIcons::PATHS['shield']);
});

it('draws the header icons and the card icons from one definition, with the same line weight', function (): void {
    $card = (string) file_get_contents(resource_path('views/components/home/guides.blade.php'));
    $icon = (string) file_get_contents(resource_path('views/components/storefront/tool-icon.blade.php'));

    expect($card)->toContain('\App\Support\GuideIcons::PATHS')->not->toContain('M3.75 7.5h16.5')
        ->and($icon)->toContain('\App\Support\GuideIcons::forTool')->not->toContain('M3.75 7.5h16.5')->toContain('stroke-width="1.5"')
        ->and($card)->toContain('stroke-width="1.5"');

    // Every Tools link has an icon, and an unknown name has none.
    expect(GuideIcons::forTool('size-guide'))->toBe(GuideIcons::PATHS['ruler'])
        ->and(GuideIcons::forTool('technical-features'))->toBe(GuideIcons::PATHS['shield'])
        ->and(GuideIcons::forTool('no-such-page'))->toBe([]);
});

it('shows the same ruler and shield in the mobile menu\'s Tools group', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<ul[^>]*data-drawer-tools.*?</ul>#s', $html, $drawer);
    preg_match_all('#data-tool-icon="([^"]+)"[^>]*>(.*?)</svg>#s', $drawer[0], $icons, PREG_SET_ORDER);

    expect($icons)->toHaveCount(2)
        ->and(html_entity_decode($icons[0][2]))->toContain(GuideIcons::PATHS['ruler'][1])
        ->and(html_entity_decode($icons[1][2]))->toContain('M9 12.75 11.25 15');
});
