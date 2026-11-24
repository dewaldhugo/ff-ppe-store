<?php

declare(strict_types=1);

use Lunar\Admin\Models\Staff;

// The admin sign-in page has a photo background under a dark overlay (resources/views/admin/login-background.blade.php,
// registered on the panel in AppServiceProvider). It is scoped to Filament's simple layout, so only the sign-in style pages use it.

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('puts the photo and a dark overlay behind the admin sign-in page', function (): void {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    preg_match('#<style>\s*\.fi-simple-layout \{(.*?)\}\s*</style>#s', $html, $style);

    expect($style[1] ?? '')->toContain('linear-gradient(color-mix(in srgb, black 85%, transparent), color-mix(in srgb, black 85%, transparent))')
        ->toContain("url('".asset('images/admin/login-bg.webp')."')")
        ->toContain('center / cover no-repeat')
        // The overlay sits on top of the photo (first layer), not under it.
        ->and(strpos($style[1], 'linear-gradient'))->toBeLessThan(strpos($style[1], 'url('))
        ->and(substr_count($html, 'images/admin/login-bg.webp'))->toBe(1)
        ->and($html)->toContain('class="fi-simple-layout"');
});

it('has the photo on disk as a web image of a sensible size', function (): void {
    $path = public_path('images/admin/login-bg.webp');

    expect(is_file($path))->toBeTrue()
        ->and(filesize($path))->toBeLessThan(300_000)
        ->and(getimagesize($path)[0])->toBeGreaterThanOrEqual(1400)
        ->and(getimagesize($path)['mime'])->toBe('image/webp');
});

it('keeps the style scoped to the simple layout, so the dashboard is not restyled', function (): void {
    $source = file_get_contents(resource_path('views/admin/login-background.blade.php'));

    preg_match_all('/^\s*([.#][^\s{]+)[^{]*\{/m', preg_replace('/\{\{--.*?--\}\}/s', '', $source), $selectors);

    expect($selectors[1])->toBe(['.fi-simple-layout']);

    // The dashboard still loads, and only the sign-in style layout carries the class.
    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    expect($this->get('/admin')->assertOk()->getContent())->not->toContain('class="fi-simple-layout"');
});
