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

it('lays out the photo on the left and the copy on the right, then a rule, the tagline across the container, then the button', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    // Two columns from the lg breakpoint: the photo first (left), the three paragraphs second (right).
    preg_match('#<div[^>]*data-about-columns[^>]*>#', $html, $columns);

    $image = strpos($html, 'data-about-image');
    $copy = strpos($html, 'data-about-copy');
    $rule = strpos($html, 'data-about-rule');
    $tagline = strpos($html, 'data-about-tagline');
    $button = strpos($html, 'href="'.route('contact').'"', $tagline);

    // The photo column is 45% and the copy column 55% (the photo column was 50%, then made 5% narrower).
    expect($columns[0] ?? '')->toContain('lg:grid-cols-[minmax(0,45fr)_minmax(0,55fr)]')
        ->and($image)->toBeLessThan($copy)
        ->and($copy)->toBeLessThan($rule)
        ->and($rule)->toBeLessThan($tagline)
        ->and($tagline)->toBeLessThan($button)
        ->and($html)->toContain('<hr class="mt-12 border-0 border-t border-border" data-about-rule>');

    // All three paragraphs in the standard text size: no larger lead paragraph.
    preg_match('#<div[^>]*data-about-copy[^>]*>(.*?)</div>#s', $html, $copyBlock);

    expect(substr_count($copyBlock[1], '<p>'))->toBe(3)
        ->and($copyBlock[1])->not->toContain('text-lg')->not->toContain('text-xl')->not->toContain('<p class');

    // The tagline runs the full width of the container: no width cap on it.
    preg_match('#<p[^>]*data-about-tagline[^>]*>#', $html, $tag);

    expect($tag[0])->toContain('w-full')->not->toMatch('/max-w-/');
});

it('has an h2 above the columns, under the page heading', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<h2[^>]*data-about-subheading[^>]*>(.*?)</h2>#s', $html, $heading);

    expect($heading[1] ?? '')->toBe('Your Head to Toe PPE Partner - Frontline Safety Gear')
        ->and(strpos($html, 'About Frontline</h1>'))->toBeLessThan(strpos($html, 'data-about-subheading'))
        ->and(strpos($html, 'data-about-subheading'))->toBeLessThan(strpos($html, 'data-about-columns'));
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
