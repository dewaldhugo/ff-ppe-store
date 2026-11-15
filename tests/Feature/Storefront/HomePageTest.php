<?php

declare(strict_types=1);

use App\Domain\Catalogue\Home\HeroImage;

function heroSection(string $html): string
{
    preg_match('#<section[^>]*data-home-hero.*?</section>#s', $html, $hero);

    return $hero[0];
}

/** A real WebP on disk, so the hero can read its dimensions. */
function fakeHeroFile(): string
{
    if (! function_exists('imagewebp')) {
        test()->markTestSkipped('GD has no WebP support.');
    }

    $path = tempnam(sys_get_temp_dir(), 'hero').'.webp';
    $canvas = imagecreatetruecolor(1200, 900);
    imagewebp($canvas, $path);

    return $path;
}

it('has the new hero copy, with one h1 and no placeholder text', function (): void {
    $html = $this->get('/')->assertOk()->getContent();
    $hero = heroSection($html);

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($hero)->toContain('Trade supplier')
        ->and($hero)->toMatch('#<h1[^>]*>Safety footwear for UK businesses</h1>#')
        ->and($hero)->toContain('S1P, S3 and ESD trainers, shoes and boots in a wide range of sizes. All prices shown exclusive of VAT.')
        ->and($html)->not->toContain('Content coming soon.')
        ->and($html)->not->toContain('full size runs');
});

it('links the primary button to safety footwear and the secondary button to contact', function (): void {
    $hero = heroSection($this->get('/')->getContent());

    expect($hero)->toMatch('#<a href="'.preg_quote(route('category', 'safety-footwear'), '#').'"[^>]*bg-brand-teal[^>]*text-brand-navy[^>]*>Shop safety footwear</a>#')
        ->and($hero)->toMatch('#<a href="'.preg_quote(route('contact'), '#').'"[^>]*border-brand-teal[^>]*>Contact us for trade enquiries</a>#');
});

it('has no hero photo background any more', function (): void {
    expect(is_file(public_path('images/home-hero.jpg')))->toBeFalse()
        ->and(heroSection($this->get('/')->getContent()))->not->toContain('home-hero.jpg');
});

it('gives the text the full width when there is no hero image file', function (): void {
    app()->instance(HeroImage::class, new HeroImage(sys_get_temp_dir().'/no-such-hero.webp', fn (): string => 'https://example.test/hero.webp'));

    $hero = heroSection($this->get('/')->getContent());

    expect($hero)->not->toContain('<img')
        ->and($hero)->not->toContain('data-hero-image')
        ->and($hero)->not->toContain('md:grid-cols-2')
        ->and($hero)->toContain('data-hero-text');
});

it('shows the hero image in a second column from md up, after the text, with its size set', function (): void {
    $path = fakeHeroFile();
    app()->instance(HeroImage::class, new HeroImage($path, fn (): string => 'https://example.test/hero.webp'));

    $hero = heroSection($this->get('/')->getContent());

    expect($hero)->toContain('md:grid-cols-2')
        ->and($hero)->toMatch('#<div class="hidden md:block" data-hero-image>#')
        ->and($hero)->toContain('src="https://example.test/hero.webp"')
        ->and($hero)->toContain('width="1200"')
        ->and($hero)->toContain('height="900"')
        ->and($hero)->toContain('alt=""')
        // The text comes first in the document, so it comes first on a phone.
        ->and(strpos($hero, 'data-hero-text'))->toBeLessThan(strpos($hero, 'data-hero-image'));

    unlink($path);
});

it('renders no image column when the built file has no URL', function (): void {
    $path = fakeHeroFile();
    app()->instance(HeroImage::class, new HeroImage($path, fn (): string => ''));

    expect(heroSection($this->get('/')->getContent()))->not->toContain('<img');

    unlink($path);
});

it('registers resources/images with Vite so the hero is built', function (): void {
    expect((string) file_get_contents(resource_path('js/app.js')))->toContain('import.meta.glob')
        ->and(HeroImage::SOURCE)->toBe('resources/images/hero/hero.webp');
});

it('gives the accent button a teal fill with navy text that meets AA', function (): void {
    $button = (string) file_get_contents(resource_path('views/components/ui/button.blade.php'));

    expect($button)->toContain('bg-brand-teal text-brand-navy');
});

it('claims no testimonials or client logos on the home page', function (): void {
    $html = strtolower($this->get('/')->getContent());

    expect($html)->not->toContain('testimonial')->and($html)->not->toContain('trusted by');
});
