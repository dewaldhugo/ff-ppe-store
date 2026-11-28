<?php

declare(strict_types=1);
use App\Models\User;

// The Size Guide and Technical Features links are a Tools menu beside the search box (resources/views/components/storefront/header.blade.php),
// not items of the main menu bar, and the search box is no longer the whole width of the bar.

it('puts a Tools menu beside the search, after it and before the favourites and cart', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    $search = strpos($html, 'role="search"');
    $tools = strpos($html, 'data-tools-menu');

    expect($search)->not->toBeFalse()->and($tools)->not->toBeFalse()
        ->and($tools)->toBeGreaterThan($search)
        // Straight after the search form: nothing but the form's own markup between them.
        ->and(substr($html, (int) $search, (int) $tools - (int) $search))->not->toContain('data-cart-link');
});

it('lists the size guide and the technical features in the Tools menu, in that order, with their own links', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<details.*?data-tools-menu.*?</details>#s', $html, $tools);
    preg_match_all('#<a href="([^"]+)"[^>]*>([^<]+)</a>#', $tools[0], $links, PREG_SET_ORDER);

    expect(array_column($links, 1))->toBe([route('size-guide'), route('technical-features')])
        ->and(array_map('trim', array_column($links, 2)))->toBe(['Size Guide', 'Technical Features'])
        ->and($tools[0])->toContain('data-tools-button')->toContain('>Tools');
});

it('is a native details element, so it opens without JavaScript, and is for large screens only', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<details[^>]*data-tools-menu[^>]*>#s', $html, $details);

    expect($details[0])->toContain('hidden lg:block')
        // The menu closes on a click outside and on Escape.
        ->and($details[0])->toContain("x-on:click.outside=\"\$el.removeAttribute('open')\"")
        ->and($details[0])->toContain("x-on:keydown.escape=\"\$el.removeAttribute('open')\"");
});

it('takes the size guide and technical features out of the main menu bar', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<nav aria-label="Main".*?</nav>#s', $html, $nav);

    expect($nav[0])->not->toContain('Size Guide')->not->toContain('Technical Features')
        // The rest of the bar is as it was.
        ->and($nav[0])->toContain('>Brands<')->toContain('>Wear Trials<')->toContain('>PPE Health Check<')->toContain('>About<')->toContain('>Contact<');
});

it('marks Tools as current on the size guide and the technical features pages, and only there', function (): void {
    foreach (['size-guide', 'technical-features'] as $name) {
        $html = $this->get(route($name))->assertOk()->getContent();

        preg_match('#<summary[^>]*data-tools-button[^>]*>#', $html, $summary);
        preg_match('#<div[^>]*data-tools-list.*?</div>#s', $html, $list);

        expect($summary[0])->toMatch('/(?<![:\w-])bg-surface-muted/')
            ->and(substr_count($list[0], 'aria-current="page"'))->toBe(1)
            ->and($list[0])->toMatch('#<a href="'.preg_quote(route($name), '#').'"[^>]*aria-current="page"#');
    }

    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<summary[^>]*data-tools-button[^>]*>#', $html, $summary);
    preg_match('#<div[^>]*data-tools-list.*?</div>#s', $html, $list);

    // Only the hover tint is on the button, and no link in the list is current.
    expect($summary[0])->not->toMatch('/(?<![:\w-])bg-surface-muted/')
        ->and($list[0])->not->toContain('aria-current');
});

it('keeps the Tools links in the mobile menu, in a group of their own', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#id="mobile-menu".*?</nav>#s', $html, $drawer);
    preg_match('#<ul[^>]*data-drawer-tools.*?</ul>#s', $drawer[0], $tools);

    expect($drawer[0])->toContain('>Tools</span>')
        ->and($tools[0])->toContain('href="'.route('size-guide').'"')->toContain('href="'.route('technical-features').'"')
        // The old place of the two links, in the drawer's main list, is gone: each is in the Tools group only.
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
