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

it('shows no RRP next to the price, on a listing or a product page', function (): void {
    $product = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();
    $listing = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    foreach ([$product, $listing] as $html) {
        expect($html)->not->toContain('data-rrp')
            ->and(strip_tags($html))->not->toContain('RRP')
            ->and($html)->not->toContain('<s ');
    }
});

it('shows the price ex VAT, not the VAT inclusive figure', function (): void {
    $this->get(route('product', 'safety-jogger-ligero'))
        ->assertSee('£68.90')
        ->assertDontSee('£82.68')
        ->assertDontSee('£91.80');
});
