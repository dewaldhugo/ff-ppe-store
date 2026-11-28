<?php

declare(strict_types=1);

use App\Domain\Catalogue\Brands\BrandDirectory;
use App\Domain\Catalogue\Home\HomeBrandStrip;
use Meilisearch\Client;
use Tests\Support\SearchIndex;

// The "Shop by brand" logo strip on the home page (<x-home.brand-strip>), straight under the trust strip: the brands of
// /brands (BrandDirectory), most products first, each logo a link to that brand's products.

/** The brand strip section of the home page, or an empty string. */
function brandStripSection(string $html): string
{
    preg_match('#<section[^>]*data-home-brands.*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('shows the brands with products as links to their products, under the trust strip', function (): void {
    SearchIndex::use();

    $html = $this->get(route('home'))->assertOk()->getContent();
    $strip = brandStripSection($html);

    expect($strip)->not->toBe('')
        ->and($strip)->toContain('aria-labelledby="home-brands"')->toContain('id="home-brands"')->toContain('Shop by brand')
        ->and($strip)->toContain('data-home-brand="safety-jogger"')
        ->and($strip)->toContain('href="'.route('brands.show', 'safety-jogger').'"')
        ->and(strpos($html, 'data-home-trust'))->toBeLessThan(strpos($html, 'data-home-brands'))
        ->and(strpos($html, 'data-home-brands'))->toBeLessThan(strpos($html, 'data-home-products'));
});

it('links to every brand from the strip and to the brands page', function (): void {
    SearchIndex::use();

    $strip = brandStripSection($this->get(route('home'))->assertOk()->getContent());

    expect($strip)->toContain('data-home-brands-all')
        ->and($strip)->toMatch('#<a[^>]*href="'.preg_quote(route('brands'), '#').'"[^>]*data-home-brands-all|<a[^>]*data-home-brands-all[^>]*href="'.preg_quote(route('brands'), '#').'"#')
        ->and($strip)->toContain('View all brands');
});

it('shows each tile on white and turns it light teal on hover', function (): void {
    SearchIndex::use();

    $strip = brandStripSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<a href="[^"]*/brands/[^"]*"[^>]*?\sclass="([^"]*)"#', $strip, $tiles);

    expect($tiles[1])->not->toBeEmpty();

    foreach ($tiles[1] as $classes) {
        expect($classes)->toContain(' bg-white ')->toContain('hover:bg-brand-teal-light')->not->toContain('bg-border/40')->not->toContain('hover:bg-surface-muted');
    }
});

it('shows the same brands as /brands, most products first, cut to the limit', function (): void {
    SearchIndex::use();

    $expected = array_map(fn ($tile): string => $tile->slug, app(BrandDirectory::class)->withProducts());

    preg_match_all('#data-home-brand="([^"]+)"#', brandStripSection($this->get(route('home'))->assertOk()->getContent()), $slugs);

    expect($expected)->not->toBeEmpty()
        ->and($slugs[1])->toBe(array_slice($expected, 0, 12));

    config(['homepage.brands.limit' => 2]);

    preg_match_all('#data-home-brand="([^"]+)"#', brandStripSection($this->get(route('home'))->assertOk()->getContent()), $limited);

    expect($limited[1])->toBe(array_slice($expected, 0, 2));
});

it('puts every logo in a lazy, sized image with the brand name for screen readers', function (): void {
    SearchIndex::use();

    $strip = brandStripSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<img[^>]*>#', $strip, $images);

    expect($images[0])->not->toBeEmpty();

    foreach ($images[0] as $img) {
        expect($img)->toContain('loading="lazy"')->toContain('width="360"')->toContain('height="240"')->toContain('alt=""');
    }

    expect($strip)->toContain('<span class="sr-only">Safety Jogger</span>');
});

it('is left out when there is no brand to show', function (): void {
    SearchIndex::use();
    config(['homepage.brands.limit' => 0]);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->not->toContain('data-home-brands')->not->toContain('Shop by brand');
});

it('leaves the strip out when the search index cannot be reached, and the rest of the home page still loads', function (): void {
    SearchIndex::use();

    // Point the search client at a port nothing listens on, so the brand counts really fail.
    $this->app->bind(Client::class, fn (): Client => new Client('http://127.0.0.1:1', 'unused'));

    expect(app(HomeBrandStrip::class)->tiles())->toBe([]);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->not->toContain('data-home-brands')->toContain('data-home-trust')->toContain('data-home-service');
});

it('centres View all brands, the same as Browse all categories, with the same space above', function (): void {
    SearchIndex::use();

    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<div class="([^"]*)" data-home-brands-all-wrap>#', $html, $brands);
    preg_match('#<p class="([^"]*)" data-browse-all-categories>#', $html, $categories);

    expect($brands[1] ?? '')->toContain('text-center')->toContain('mt-8')
        ->and($categories[1] ?? '')->toContain('text-center')->toContain('mt-8');
});
