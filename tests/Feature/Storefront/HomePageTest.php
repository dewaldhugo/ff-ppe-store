<?php

declare(strict_types=1);

use App\Domain\Catalogue\Home\HeroImage;
use App\Domain\Catalogue\Home\HomeFeaturedProducts;
use Lunar\Models\Url;

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
    $html = $this->get(route('home'))->assertOk()->getContent();
    $hero = heroSection($html);

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($hero)->toContain('UK TRADE PPE SUPPLIER')
        ->and($hero)->toMatch('#<h1[^>]*>When safety slips, everyone pays\.<br>Quality safety gear isn&rsquo;t optional, it&rsquo;s essential\.</h1>#')
        ->and($hero)->toContain('EN-compliant safety footwear, head, eye, and hearing protection ready for immediate dispatch. Bulk trade pricing available.')
        ->and($html)->not->toContain('Content coming soon.')
        ->and($html)->not->toContain('full size runs');
});

it('links the primary button to the shop and the secondary button to the account page, with the same corner radius', function (): void {
    $hero = heroSection($this->get(route('home'))->getContent());

    expect($hero)->toMatch('#<a href="'.preg_quote(route('shop'), '#').'"[^>]*rounded-md[^>]*bg-brand-teal[^>]*text-brand-navy[^>]*>View all products</a>#')
        ->and($hero)->toMatch('#<a href="'.preg_quote(route('account'), '#').'"[^>]*rounded-md[^>]*border-brand-teal[^>]*>Open a trade account</a>#')
        ->and($hero)->not->toContain('rounded-full');
});

it('uses one of the seven hero-bg photos as the hero background, centred, with no overlay', function (): void {
    foreach (range(1, 7) as $n) {
        expect(is_file(public_path("images/hero-bg-{$n}.jpg")))->toBeTrue();
    }

    $seen = [];

    foreach (range(1, 40) as $ignored) {
        $hero = heroSection($this->get(route('home'))->getContent());

        expect($hero)->toMatch('#<img[^>]*src="'.preg_quote(asset('images/hero-bg-'), '#').'[1-7]\.jpg"[^>]*data-hero-bg#')
            ->and($hero)->toContain('alt=""')
            ->and($hero)->toContain('width="1920"')
            ->and($hero)->toContain('height="882"')
            ->and($hero)->toContain('object-cover object-center')
            ->and($hero)->not->toContain('object-[center_')
            ->and($hero)->not->toContain('data-hero-overlay')
            ->and($hero)->not->toContain('from-brand-navy/');

        preg_match('#hero-bg-([1-7])\.jpg#', $hero, $m);
        $seen[$m[1]] = true;
    }

    // Random per page load: 40 loads showing a single image has odds of 7^-39.
    expect(count($seen))->toBeGreaterThan(1);
});

it('gives the text the full width when there is no hero image file', function (): void {
    app()->instance(HeroImage::class, new HeroImage(sys_get_temp_dir().'/no-such-hero.webp', fn (): string => 'https://example.test/hero.webp'));

    $hero = heroSection($this->get(route('home'))->getContent());

    expect($hero)->not->toContain('data-hero-image')
        ->and($hero)->not->toContain('md:grid-cols-2')
        ->and($hero)->toContain('data-hero-text');
});

it('shows the hero image in a second column from md up, after the text, with its size set', function (): void {
    $path = fakeHeroFile();
    app()->instance(HeroImage::class, new HeroImage($path, fn (): string => 'https://example.test/hero.webp'));

    $hero = heroSection($this->get(route('home'))->getContent());

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

    expect(heroSection($this->get(route('home'))->getContent()))->not->toContain('data-hero-image');

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
    $html = strtolower($this->get(route('home'))->getContent());

    expect($html)->not->toContain('testimonial')->and($html)->not->toContain('trusted by');
});

function productPicksOf(string $html): string
{
    preg_match('#<section[^>]*data-home-products.*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

it('replaces the shop by type tiles with a Featured products section of linked product cards', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();
    $section = productPicksOf($html);
    $cards = substr_count($section, 'data-home-product>');

    expect($html)->not->toContain('data-home-categories')
        ->and($html)->not->toContain('Shop by type')
        ->and($section)->toMatch('#<h2[^>]*id="home-products"[^>]*>Featured products</h2>#')
        ->and($cards)->toBeGreaterThan(0)
        ->and($cards)->toBeLessThanOrEqual(5);

    preg_match_all('#<li[^>]*data-home-product>.*?</li>#s', $section, $items);

    foreach ($items[0] as $item) {
        expect($item)->toContain('href="'.url('/products/')) // a product link, built from route('product')
            ->and($item)->toContain('loading="lazy"')
            ->and($item)->toContain('width="300"')
            ->and($item)->toContain('height="300"')
            ->and($item)->toContain('data-price');
    }
});

it('shows five products when the catalogue has at least five that can be shown', function (): void {
    $eligible = (new HomeFeaturedProducts)->pick(100)->count();

    $shown = substr_count(productPicksOf($this->get(route('home'))->getContent()), 'data-home-product>');

    expect($shown)->toBe(min(5, $eligible));
});

it('picks a different set of products from page load to page load', function (): void {
    $eligible = (new HomeFeaturedProducts)->pick(100)->count();

    if ($eligible <= 5) {
        $this->markTestSkipped('The fixture catalogue has five or fewer products to pick from.');
    }

    $sets = [];

    foreach (range(1, 25) as $ignored) {
        $sets[implode(',', (new HomeFeaturedProducts)->pick()->modelKeys())] = true;
    }

    expect(count($sets))->toBeGreaterThan(1);
});

it('only picks published products that have an image and a price', function (): void {
    $product = Url::query()->where('slug', 'safety-jogger-ligero')->sole()->element;
    $picks = new HomeFeaturedProducts;

    expect($picks->pick(100)->modelKeys())->toContain($product->id);

    $product->update(['status' => 'draft']);
    expect($picks->pick(100)->modelKeys())->not->toContain($product->id);

    $product->update(['status' => 'published']);
    $product->clearMediaCollection(config('lunar.media.collection'));
    expect($picks->pick(100)->modelKeys())->not->toContain($product->id);
});

it('shows the industry slider under the hero, with its heading', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    $hero = (int) strpos($html, 'data-home-hero');
    $industries = (int) strpos($html, 'data-home-industries');

    expect($industries)->toBeGreaterThan($hero)
        ->and($industries)->toBeLessThan((int) strpos($html, 'data-home-trust'))
        ->and($html)->toContain('Solutions for every workplace')
        ->and($html)->toContain('aria-label="Industries we supply"')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('shows the feature products section with two videos, in place of the standards chips', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-featured.*?</section>#s', $html, $section);

    expect($section[0] ?? '')->toMatch('#<h2[^>]*id="home-featured"[^>]*>Featured products</h2>#')
        ->and(substr_count($section[0], '<video'))->toBe(2)
        ->and($section[0])->toContain('md:grid-cols-2')
        ->and($section[0])->toContain('src="https://frontline-footwear.com/shop/datasheets/CADOR%20Colours%20that%20work.mp4"')
        ->and($section[0])->toContain('src="https://frontline-footwear.com/shop/datasheets/MODULO_540p.mp4"')
        ->and($section[0])->toContain('poster="https://frontline-shop.com/v1.jpg"')
        ->and($section[0])->toContain('poster="https://frontline-shop.com/v2.jpg"')
        ->and(substr_count($section[0], 'preload="metadata"'))->toBe(2)
        ->and($html)->not->toContain('data-home-standards');
});

it('puts an intro h3 under the industries heading and a light grey band above the footer', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-service[^>]*>#', $html, $service);
    preg_match('#<section class="[^"]*"[^>]*data-home-service#', $html, $serviceClass);

    expect($html)->toMatch('#Solutions for every workplace</h2>.*?<h3[^>]*data-industries-intro[^>]*>Streamline your site procurement with a single, dependable UK trade supplier\. We stock certified head-to-toe PPE, workwear, and safety footwear ready for rapid nationwide dispatch and competitive bulk pricing\.</h3>#s')
        ->and($serviceClass[0] ?? '')->toContain('bg-surface-muted');
});
