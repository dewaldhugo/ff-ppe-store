<?php

declare(strict_types=1);

/**
 * The store sells to business buyers only: every price shown is ex VAT and
 * says so. The compare price is the manufacturer's RRP, labelled as such.
 */

/**
 * The page's main content without its prices, filter placeholders and scripts.
 *
 * @return array{0: list<string>, 1: list<string>, 2: string} Price elements, RRP elements, and what is left of main.
 */
function priceElements(string $html): array
{
    preg_match('#<main.*?</main>#s', $html, $main);
    $content = (string) preg_replace('#<script.*?</script>#s', '', $main[0]);

    preg_match_all('#<p[^>]*data-price[^>]*>.*?</p>#s', $content, $prices);
    preg_match_all('#<span[^>]*data-rrp[^>]*>.*?</span>#s', $content, $rrps);

    $rest = str_replace([...$prices[0], ...$rrps[0]], '', $content);
    $rest = (string) preg_replace('#placeholder="[^"]*"#', '', $rest);

    return [$prices[0], $rrps[0], $rest];
}

it('shows every price ex VAT and labels it', function (array $route): void {
    [$prices, $rrps, $rest] = priceElements($this->get(route(...$route))->assertOk()->getContent());

    expect($prices)->not->toBeEmpty();

    foreach ($prices as $price) {
        expect($price)->toContain('ex VAT')->and($price)->not->toContain('inc VAT');
    }

    foreach ($rrps as $rrp) {
        expect($rrp)->toContain('RRP')->and($rrp)->toContain('ex VAT');
    }

    // No price is shown anywhere else on the page, unlabelled.
    expect($rest)->not->toMatch('/£\s?\d/');
})->with([
    [['category', 'safety-footwear']],
    [['search', ['q' => 'boots']]],
    [['product', 'safety-jogger-ligero']],
    [['product', 'amblers-safety-fs9']],
]);

it('labels the compare price as the manufacturer\'s RRP, never as a bare struck-through amount', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<span[^>]*data-rrp[^>]*>.*?</span>#s', $html, $rrp);

    expect($rrp[0])->toContain("<abbr title=\"Manufacturer's recommended retail price\">RRP</abbr>")
        ->and(strip_tags($rrp[0]))->toContain('RRP £76.50 ex VAT')
        ->and($html)->not->toContain('<s ')
        ->and($html)->not->toContain('<s>')
        ->and($html)->not->toContain('Was ');

    // The same on a listing.
    $listing = $this->get(route('category', 'safety-footwear'))->getContent();

    expect($listing)->toContain('data-rrp')
        ->and($listing)->not->toContain('<s ')
        ->and(strip_tags($listing))->toContain('RRP £76.50 ex VAT');
});

it('shows the price ex VAT, not the VAT inclusive figure', function (): void {
    $this->get(route('product', 'safety-jogger-ligero'))
        ->assertSee('£68.90')
        ->assertDontSee('£82.68')
        ->assertDontSee('£91.80');
});
