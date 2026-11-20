<?php

declare(strict_types=1);

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('serves the about page at /about in the standard layout', function (): void {
    expect(route('about', absolute: false))->toBe('/about');

    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toContain('<title>About us | ')
        ->and($html)->toContain('id="main"')
        ->and($html)->toContain('<footer')
        ->and($html)->toContain('<nav aria-label="Main"')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('About Frontline</h1>');
});

it('has the supplied Frontline copy and nothing from the previous company', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->not->toContain('Content coming soon.')
        ->and($html)->toContain('we draw inspiration from the people who matter most')
        ->and($html)->toContain('nationwide distribution network with robust stockholding and rigorous quality control')
        ->and($html)->toContain('we now also offer a lifestyle range that blends safety, comfort, and practicality for everyday use.')
        ->and($html)->toContain('Frontline: Innovative safety gear, designed for those who keep the UK moving.');

    foreach (['Bridge', 'Greenwich', 'Thames Water', 'Siemens', 'scaffolding'] as $old) {
        expect($html)->not->toContain($old);
    }
});

it('links the call to action to the contact page with a route', function (): void {
    $this->get(route('about'))->assertOk()->assertSee('href="'.route('contact').'"', false);
});

it('stays out of search engines only while STORE_NOINDEX is true, with no noindex of its own', function (): void {
    $this->get(route('about'))->assertOk()->assertSee('<meta name="robots" content="noindex">', false);

    config()->set('store.noindex', false);

    $this->get(route('about'))->assertOk()->assertDontSee('<meta name="robots" content="noindex">', false);
});

it('uses UK English and no em dashes in its copy', function (): void {
    $text = file_get_contents(resource_path('views/pages/about.blade.php'));

    expect($text)->not->toContain('—')
        ->and($text)->not->toMatch('/\b(organization|program|center|color|behavior)\b/i');
});

it('shows the team photo with descriptive alt text and its real size', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<img[^>]*data-about-image[^>]*>#s', $html, $img);

    expect($img[0] ?? '')->toContain(asset('images/about/frontline-team.jpg'))
        ->toMatch('#alt="[^"]{20,}"#')
        ->toContain('width="2000"')
        ->toContain('height="877"')
        ->and(is_file(public_path('images/about/frontline-team.jpg')))->toBeTrue()
        ->and(is_file(public_path('images/about/our-people.webp')))->toBeFalse()
        ->and(getimagesize(public_path('images/about/frontline-team.jpg'))[0])->toBe(2000);
});
