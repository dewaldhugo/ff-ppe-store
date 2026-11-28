<?php

declare(strict_types=1);
use App\Models\User;

// The Size Guide and Technical Features links are two links with an icon each beside the search box
// (resources/views/components/storefront/header.blade.php), not items of the main menu bar and not a drop down, and the search box
// is no longer the whole width of the bar.

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

it('does not stretch the search box across the whole bar', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<form[^>]*role="search"[^>]*>#', $html, $form);

    expect($form[0])->toContain('lg:max-w-lg')->toContain('lg:flex-1')->toContain('w-full');
});

it('keeps the favourites and cart at the right of the bar', function (): void {
    $this->actingAs(User::factory()->create(), 'web');

    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<a[^>]*data-favourites-link[^>]*>#', $html, $favourites);

    expect($favourites[0])->toContain('ml-auto')->not->toContain('lg:ml-0');
});
