<?php

declare(strict_types=1);

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('serves the about page at /about in the standard layout', function (): void {
    expect(route('about', absolute: false))->toBe('/about');

    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->toContain('<title>About The Bridge Group | ')
        ->and($html)->toContain('id="main"')
        ->and($html)->toContain('<footer')
        ->and($html)->toContain('<nav aria-label="Main"')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('About The Bridge Group</h1>');
});

it('has the real content and no longer the placeholder text', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    expect($html)->not->toContain('Content coming soon.');

    foreach (['Our Story', 'One Group, Working Together', 'Our People', 'How We Work', 'Our Standards &amp; Commitments', 'Talk To Our Team'] as $heading) {
        expect($html)->toContain('>'.$heading.'</h2>');
    }

    foreach (['Quality', 'Care', 'Ownership', 'Passion', 'Service'] as $value) {
        expect($html)->toContain('>'.$value.'</dt>');
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
