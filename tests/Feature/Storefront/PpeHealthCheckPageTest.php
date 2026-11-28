<?php

declare(strict_types=1);

// The PPE Health Check page (/ppe-health-check): the client's pitch for the online survey, with buttons to the survey on another site.

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('serves the page at /ppe-health-check in the standard layout with one heading', function (): void {
    expect(route('ppe-health-check', absolute: false))->toBe('/ppe-health-check');

    $html = $this->get(route('ppe-health-check'))->assertOk()->getContent();

    expect($html)->toContain('<title>PPE Health Check | ')
        ->and($html)->toContain('id="main"')
        ->and($html)->toContain('<footer')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toMatch('#<h1[^>]*>Is Your PPE Costing You More Than It Should\?</h1>#')
        ->and($html)->toContain('data-health-check');
});

it('has a plain navy hero with no photo or overlay behind it, and a taller hero', function (): void {
    $html = $this->get(route('ppe-health-check'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-health-check>.*?</section>#s', $html, $hero);

    expect($hero[0] ?? '')->toContain('bg-brand-navy')->toContain('py-[5.5rem]')
        ->not->toContain('<img')
        ->not->toContain('data-health-check-hero-image')
        ->not->toContain('data-health-check-hero-overlay')
        ->not->toContain('-z-10')
        ->and($html)->not->toContain('images/hero-bg-9.jpg');
});

it('ends after what it measures: no banner, About, How it works or closing call to action', function (): void {
    $html = $this->get(route('ppe-health-check'))->assertOk()->getContent();

    expect($html)->toContain('data-health-check-measures')
        ->and($html)->not->toContain('data-health-check-banner')
        ->and($html)->not->toContain('data-health-check-about')
        ->and($html)->not->toContain('data-health-check-steps')
        ->and($html)->not->toContain('data-health-check-end')
        ->and($html)->not->toContain('images/hero-bg-7.jpg')
        ->and($html)->not->toContain('Practical PPE support, not just products.')
        // What it measures is the last section of the page, so the footer follows it.
        ->and(strpos($html, 'data-health-check-measures'))->toBeLessThan(strpos($html, '<footer'));
});

it('sends people to the survey from the button, in a new tab, safely', function (): void {
    expect(config('store.ppe_health_check_url'))->toBe('https://frontline-general-health-and-safety.scoreapp.com/');

    $html = $this->get(route('ppe-health-check'))->assertOk()->getContent();

    preg_match_all('#<a\s[^>]*data-health-check-link[^>]*>.*?</a>#s', $html, $links);

    expect($links[0])->toHaveCount(1);

    foreach ($links[0] as $link) {
        expect($link)->toContain('href="https://frontline-general-health-and-safety.scoreapp.com/"')
            ->toContain('target="_blank"')
            ->toContain('rel="noopener"')
            ->toContain('Get my free PPE scorecard')
            ->toContain('(opens in a new tab)');
    }
});
it('follows the survey address in config', function (): void {
    config()->set('store.ppe_health_check_url', 'https://survey.example.test/check');

    $html = $this->get(route('ppe-health-check'))->assertOk()->getContent();

    expect(substr_count($html, 'href="https://survey.example.test/check"'))->toBe(1);
});

it('has the client copy: the five areas and the privacy note', function (): void {
    $html = $this->get(route('ppe-health-check'))->assertOk()->getContent();

    preg_match('#data-health-check-areas.*?</ul>#s', $html, $areas);

    preg_match_all('#<h3[^>]*>(.*?)</h3>#s', $areas[0] ?? '', $areaTitles);

    expect(array_map(trim(...), $areaTitles[1]))->toBe(['Compliance and Standards', 'Selection and Fit', 'Purchasing and Spend', 'Supply Reliability', 'Supplier Service and Admin'])
        ->and($html)->toContain('Five areas that decide how well your PPE protects you.')
        ->and($html)->toContain('There is no obligation.')
        // The privacy note links to this site's own policy, not the survey site's.
        ->and(substr_count($html, 'href="'.route('privacy').'"'))->toBeGreaterThanOrEqual(1);
});

it('shows an example scorecard whose lowest score is the one it names', function (): void {
    $html = $this->get(route('ppe-health-check'))->assertOk()->getContent();

    preg_match('#data-health-check-example.*?</ul>#s', $html, $card);
    preg_match_all('#aria-label="([^"]+), example score"[^>]*aria-valuenow="(\d+)"#', $card[0] ?? '', $rows, PREG_SET_ORDER);

    $scores = array_column($rows, 2, 1);
    asort($scores);

    expect($html)->toContain('Example report')
        ->and($scores)->toHaveCount(3)
        ->and(array_key_first($scores))->toBe('Purchasing and Spend')
        ->and($html)->toContain('Lowest-scoring section: <span class="font-semibold text-ink">Purchasing and Spend</span>');
});

it('is linked from the header and the footer, with the current page marked in the header', function (): void {
    $html = $this->get(route('ppe-health-check'))->assertOk()->getContent();

    preg_match('#<nav aria-label="Main".*?</nav>#s', $html, $header);
    preg_match('#<footer.*?</footer>#s', $html, $footer);

    expect($header[0] ?? '')->toMatch('#<a href="'.preg_quote(route('ppe-health-check'), '#').'"[^>]*aria-current="page"[^>]*>PPE Health Check</a>#')
        ->and($footer[0] ?? '')->toContain('href="'.route('ppe-health-check').'"');
});

it('is linked from the mobile menu too', function (): void {
    preg_match('#<nav aria-label="Mobile".*?</nav>#s', $this->get(route('home'))->assertOk()->getContent(), $drawer);

    expect($drawer[0] ?? '')->toContain('href="'.route('ppe-health-check').'"')->toContain('href="'.route('wear-trials').'"');
});

it('stays out of search engines only while STORE_NOINDEX is true, with no noindex of its own', function (): void {
    $this->get(route('ppe-health-check'))->assertOk()->assertSee('<meta name="robots" content="noindex">', false);

    config()->set('store.noindex', false);

    $this->get(route('ppe-health-check'))->assertOk()->assertDontSee('<meta name="robots" content="noindex">', false);
});

it('uses UK English and no em dashes in its copy', function (): void {
    $text = strip_tags(file_get_contents(resource_path('views/pages/ppe-health-check.blade.php')));

    expect($text)->not->toContain('—')
        ->and($text)->not->toMatch('/\b(organization|program|center|color|behavior)\b/i');
});
