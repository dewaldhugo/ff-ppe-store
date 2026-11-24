<?php

declare(strict_types=1);

// The technical features page: the pills are a menu down the left from the lg breakpoint, with the panels to the right.
// Below lg they stay as wrapped pills above the content. Behaviour (Alpine) is checked in the browser, not here.

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('puts the menu in a left column and the panels in a right column from lg', function (): void {
    $html = $this->get(route('technical-features'))->assertOk()->getContent();

    preg_match('#<div[^>]*data-tf-layout[^>]*>#', $html, $layout);
    preg_match('#<div[^>]*data-tf-menu[^>]*>#s', $html, $menu);
    preg_match('#<div[^>]*data-tf-content[^>]*>#', $html, $content);

    expect($layout[0] ?? '')->toContain('lg:grid')->toContain('lg:grid-cols-[16rem_minmax(0,1fr)]')
        ->and($menu[0] ?? '')->toContain('role="tablist"')->toContain('lg:flex-col')->toContain('lg:sticky')->toContain('flex-wrap')
        ->and($content[0] ?? '')->toContain('min-w-0')
        // The menu comes first in the page, so it is the left column and the first thing a keyboard user reaches.
        ->and(strpos($html, 'data-tf-layout'))->toBeLessThan(strpos($html, 'data-tf-menu'))
        ->and(strpos($html, 'data-tf-menu'))->toBeLessThan(strpos($html, 'data-tf-content'));
});

it('makes each pill the full width of the menu and left aligned from lg', function (): void {
    $html = $this->get(route('technical-features'))->assertOk()->getContent();

    // The static class attribute (a space before it), not the Alpine :class one that follows it.
    preg_match('#role="tab"[^>]*?\sclass="([^"]*)"#s', $html, $button);

    expect($button[1] ?? '')->toContain('lg:w-full')->toContain('lg:text-left');
});

it('reports the tab list as vertical on wide screens and keeps every tab and panel', function (): void {
    $html = $this->get(route('technical-features'))->assertOk()->getContent();

    expect($html)->toContain(":aria-orientation=\"vertical ? 'vertical' : 'horizontal'\"")
        ->and($html)->toContain('@keydown.arrow-down.prevent="move(1)"')
        ->and($html)->toContain('@keydown.arrow-up.prevent="move(-1)"');

    preg_match_all("#\\{ id: '([a-z-]+)', label: '#", $html, $tabs);
    preg_match_all('#<section id="panel-([a-z-]+)" role="tabpanel"#', $html, $panels);

    expect($tabs[1])->toHaveCount(10)
        ->and($panels[1])->toBe($tabs[1]);
});
