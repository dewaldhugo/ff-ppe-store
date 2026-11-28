<?php

declare(strict_types=1);

// The PPE Health Check band on the home page (<x-home.health-check>), above "Shop by brand".

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('shows the health check band above the brand strip and below the trust strip, with its own heading', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-health-check.*?</section>#s', $html, $section);

    expect($section[0] ?? '')->toContain('aria-labelledby="home-health-check"')
        ->toContain('id="home-health-check"')
        ->toContain('Is Your PPE Costing You More Than It Should?')
        ->toContain('Free. 12 questions. About 3 minutes.')
        ->and(strpos($html, 'data-home-trust'))->toBeLessThan(strpos($html, 'data-home-health-check'))
        ->and(strpos($html, 'data-home-health-check'))->toBeLessThan(strpos($html, 'data-home-products'))
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('sends the main button to the survey in a new tab and the other to the Health Check page', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<a\s[^>]*data-home-health-check-survey[^>]*>.*?</a>#s', $html, $survey);
    preg_match('#<a\s[^>]*data-home-health-check-more[^>]*>.*?</a>#s', $html, $more);

    expect($survey[0] ?? '')->toContain('href="'.config('store.ppe_health_check_url').'"')
        ->toContain('target="_blank"')->toContain('rel="noopener"')->toContain('(opens in a new tab)')
        ->and($more[0] ?? '')->toContain('href="'.route('ppe-health-check').'"');
});

it('shows both buttons on a phone', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match_all('#<a\s[^>]*data-home-health-check-(?:survey|more)[^>]*>#', $html, $buttons);

    expect($buttons[0])->toHaveCount(2);

    foreach ($buttons[0] as $button) {
        expect($button)->not->toMatch('#class="[^"]*\bhidden\b#')->not->toContain('max-sm:hidden');
    }
});

it('shows an example scorecard labelled as an example', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#data-home-health-check-example.*?</ul>#s', $html, $card);

    expect($card[0] ?? '')->toContain('Example report')
        ->and(substr_count($card[0] ?? '', 'role="meter"'))->toBe(3);
});

it('follows the survey address and the wording in config', function (): void {
    config()->set('store.ppe_health_check_url', 'https://survey.example.test/check');
    config()->set('homepage.health_check.title', 'A different heading');

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('href="https://survey.example.test/check"')->toContain('A different heading');
});

it('is left out when there is no title or no survey address', function (): void {
    config()->set('homepage.health_check.title', '');

    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-home-health-check');

    config()->set('homepage.health_check.title', 'Heading');
    config()->set('store.ppe_health_check_url', '');

    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-home-health-check');
});

it('uses UK English and no em dashes in the band and its config', function (): void {
    $text = strip_tags(file_get_contents(resource_path('views/components/home/health-check.blade.php'))).json_encode(config('homepage.health_check'));

    expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(organization|program|center|color|behavior)\b/i');
});

it('shows the same example scorecard as the Health Check page', function (): void {
    $card = function (string $url, string $marker): string {
        preg_match('#<div[^>]*'.$marker.'[^>]*>.*?Lowest-scoring section:.*?</p>\s*</div>#s', test()->get($url)->assertOk()->getContent(), $m);

        return preg_replace('#\s*data-[a-z-]*example\s*#', ' ', preg_replace('/\s+/', ' ', $m[0] ?? ''));
    };

    $home = $card(route('home'), 'data-home-health-check-example');
    $page = $card(route('ppe-health-check'), 'data-health-check-example');

    expect($home)->not->toBe('')
        ->and($home)->toContain('Developing')->toContain('Lowest-scoring section:')
        ->and($home)->toBe($page);
});

it('is on brand-charcoal, the colour of the footer and the top bar, with a teal eyebrow that is readable on it', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-health-check[^>]*>#', $html, $open);
    preg_match('#<section[^>]*data-home-health-check.*?</section>#s', $html, $section);

    expect($open[0])->toContain('bg-brand-charcoal')->not->toContain('bg-brand-navy')->not->toContain('bg-brand-teal-dark')->toContain('surface-dark')->toContain('text-white')
        // The teal is 6.7:1 on the charcoal, the same pair as the teal accents on the top bar.
        ->and($section[0])->toMatch('#<p class="[^"]*\btext-brand-teal\b(?!-)[^"]*">[^<]+</p>#');
});
