<?php

declare(strict_types=1);

use Tests\Support\HomeSpotlights;

// The testimonials on the home page (<x-home.testimonials>), from config/homepage.php: three quotes, each with the person's role
// and sector, between the featured products and the service strip. Nobody is named, and the copy carries no dashes.

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('shows the heading and the three customer quotes, each with a role and a sector', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-testimonials.*?</section>#s', $html, $section);

    // Just the heading text: no teal rule under it (the shared heading's divider is switched off).
    expect($section)->not->toBeEmpty()
        ->and($section[0])->toContain('What our customers say')
        ->not->toContain('h-1 w-12 rounded-full bg-brand-teal');

    preg_match_all('#<p data-testimonial-quote>(.*?)</p>#s', $section[0], $quotes);
    preg_match_all('#data-testimonial-role>(.*?)</span>#s', $section[0], $roles);
    preg_match_all('#data-testimonial-sector>(.*?)</span>#s', $section[0], $sectors);

    expect($quotes[1])->toHaveCount(3)
        ->and($quotes[1][0])->toContain('juggling three different vendors')->toContain('cut our admin time in half')
        ->and($quotes[1][1])->toContain('kitting out 150+ site operatives')->toContain('EN ISO 20345')->toContain('Return rates and comfort complaints dropped immediately.')
        ->and($quotes[1][2])->toContain('Reliable stock, sharp trade pricing')->toContain('Exactly what a PPE supplier should be.')
        ->and($roles[1])->toBe(['Procurement Manager', 'Health &amp; Safety Lead', 'Site Manager'])
        ->and($sectors[1])->toBe(['Logistics &amp; Warehousing', 'Commercial Construction', 'Civil Engineering']);
});

it('marks each testimonial up as a figure with a quote and a caption', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-testimonials.*?</section>#s', $html, $section);

    expect(substr_count($section[0], '<figure'))->toBe(3)
        ->and(substr_count($section[0], '<blockquote'))->toBe(3)
        ->and(substr_count($section[0], '<figcaption'))->toBe(3)
        ->and($section[0])->toContain('aria-labelledby="home-testimonials"')->toContain('id="home-testimonials"');
});

it('sits after the featured products and before the service strip', function (): void {
    HomeSpotlights::seed();

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(strpos($html, 'data-home-featured'))->not->toBeFalse()
        ->and(strpos($html, 'data-home-featured'))->toBeLessThan(strpos($html, 'data-home-testimonials'))
        ->and(strpos($html, 'data-home-testimonials'))->toBeLessThan(strpos($html, 'data-home-service'));
});

it('is left out when there are no testimonials, or when an item has no quote', function (): void {
    config(['homepage.testimonials.items' => []]);

    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-home-testimonials')->not->toContain('What our customers say');

    config(['homepage.testimonials.items' => [
        ['quote' => '   ', 'role' => 'Nobody', 'sector' => 'Nothing'],
        ['quote' => 'Good boots.', 'role' => 'Site Manager', 'sector' => ''],
    ]]);

    $html = $this->get(route('home'))->assertOk()->getContent();

    // Only the item with a quote shows, and with no sector the sector line is left out.
    expect(substr_count($html, 'data-testimonial>'))->toBe(1)
        ->and($html)->toContain('Good boots.')->not->toContain('Nobody')->not->toContain('data-testimonial-sector');
});

it('escapes what the config says, so a quote can never inject markup', function (): void {
    config(['homepage.testimonials.items' => [['quote' => '<script>alert(1)</script> Fine boots', 'role' => '<b>Boss</b>', 'sector' => 'A & B']]]);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('<b>Boss</b>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; Fine boots')->toContain('A &amp; B');
});

it('keeps the copy free of dashes and names nobody', function (): void {
    $copy = require config_path('homepage.php');
    $items = $copy['testimonials']['items'];

    expect($items)->toHaveCount(3);

    foreach ($items as $item) {
        // No em or en dashes in customer-facing copy (CLAUDE.md), and only a role and a sector: no name, no company.
        expect(implode(' ', $item))->not->toContain('—')->not->toContain('–')
            ->and(array_keys($item))->toBe(['quote', 'role', 'sector']);
    }
});
