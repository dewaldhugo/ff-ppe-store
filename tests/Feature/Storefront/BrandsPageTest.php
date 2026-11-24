<?php

declare(strict_types=1);

use App\Models\StorefrontBrand;
use Tests\Support\SearchIndex;

beforeEach(function (): void {
    SearchIndex::use();
});

/**
 * Product names in a listing grid.
 *
 * @return list<string>
 */
function brandGridNames(string $html): array
{
    preg_match('#<ul[^>]*data-testid="product-grid".*?</ul>#s', $html, $grid);
    preg_match_all('#<h3[^>]*>\s*<a[^>]*>\s*(.*?)\s*</a>#s', $grid[0] ?? '', $matches);

    return array_map(trim(...), $matches[1]);
}

it('shows every brand with published products as a link to its products', function (): void {
    $html = $this->get(route('brands'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-brand="safety-jogger"')
        ->toContain('data-brand="amblers"')
        ->toContain('href="'.route('brands.show', 'safety-jogger').'"');
});

it('starts each tile a shade darker than the page and turns it white on hover, like the home page strip', function (): void {
    $html = $this->get(route('brands'))->assertOk()->getContent();

    // The link has its attributes on separate lines, and an Alpine :class before the plain class, so match the plain one.
    preg_match_all('#<a\s+href="[^"]*/brands/[^"]*"[^>]*?\sclass="([^"]*)"#s', $html, $tiles);

    expect($tiles[1])->not->toBeEmpty();

    foreach ($tiles[1] as $classes) {
        expect($classes)->toContain('bg-border/40')->toContain('hover:bg-white')->not->toContain('hover:bg-surface-muted');
    }
});

it('lists brands with the most products first', function (): void {
    $html = $this->get(route('brands'))->assertOk()->getContent();

    preg_match_all('#<li[^>]*data-brand="[^"]+".*?data-brand-count[^>]*>\s*(\d+)\s*</span>#s', $html, $matches);
    $counts = array_map(intval(...), $matches[1]);

    expect($counts)->not->toBeEmpty()->and($counts)->toBe(collect($counts)->sortDesc()->values()->all());
});

it('shows a Lunar brand under the storefront brand it belongs to', function (): void {
    // The fixture brand "Amblers Safety" is a member of the storefront brand Amblers.
    expect(StorefrontBrand::query()->where('slug', 'amblers')->sole()->members->pluck('name')->all())
        ->toContain('Amblers Safety');

    $html = $this->get(route('brands.show', 'amblers'))->assertOk()->getContent();

    expect(brandGridNames($html))->toContain('FS9', 'FS65')->not->toContain('LIGERO')
        ->and($html)->toContain('<h1')->toContain('Amblers');
});

it('lists several brands together on one page', function (): void {
    $html = $this->get(route('brands.show', 'amblers,safety-jogger'))->assertOk()->getContent();

    expect(brandGridNames($html))->toContain('FS9', 'LIGERO');
});

it('gives each selection one URL: sorted, lower case, no repeats', function (string $given): void {
    $this->get('/brands/'.$given.'?sort=price-asc')
        ->assertStatus(301)
        ->assertRedirect(route('brands.show', 'amblers,safety-jogger').'?sort=price-asc');
})->with(['safety-jogger,amblers', 'Amblers,Safety-Jogger', 'amblers,safety-jogger,amblers']);

it('returns 404 for an unknown brand', function (): void {
    $this->get('/brands/not-a-brand')->assertNotFound();
    $this->get('/brands/amblers,not-a-brand')->assertNotFound();
});

it('keeps filters inside the chosen brands', function (): void {
    // Centek is not one of the chosen brands, so filtering on it finds nothing.
    $html = $this->get(route('brands.show', 'amblers').'?brand[0]=Centek')->assertOk()->getContent();

    expect(brandGridNames($html))->toBe([]);
});

it('points the canonical at the unfiltered brand page', function (): void {
    $this->get(route('brands.show', 'amblers').'?sort=price-asc')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.route('brands.show', 'amblers').'">', false);
});
