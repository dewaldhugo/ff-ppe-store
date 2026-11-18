<?php

declare(strict_types=1);

use App\Domain\Catalogue\Home\HeroImage;
use App\Domain\Catalogue\Home\HomeFeaturedProducts;
use App\Domain\Catalogue\Urls\CollectionPath;
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
        ->and($cards)->toBeLessThanOrEqual(10);

    preg_match_all('#<li[^>]*data-home-product>.*?</li>#s', $section, $items);

    foreach ($items[0] as $item) {
        expect($item)->toContain('href="'.url('/products/')) // a product link, built from route('product')
            ->and($item)->toContain('loading="lazy"')
            ->and($item)->toContain('width="300"')
            ->and($item)->toContain('height="300"')
            ->and($item)->toContain('data-price');
    }
});

it('shows ten products when the Safety Footwear category has at least ten that can be shown', function (): void {
    $eligible = (new HomeFeaturedProducts)->pick(100)->count();

    $shown = substr_count(productPicksOf($this->get(route('home'))->getContent()), 'data-home-product>');

    expect($shown)->toBe(min(10, $eligible));
});

it('picks a different set of products from page load to page load', function (): void {
    $eligible = (new HomeFeaturedProducts)->pick(100)->count();

    if ($eligible <= 10) {
        $this->markTestSkipped('The fixture catalogue has ten or fewer products to pick from.');
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

it('shows the Product Spotlight as one image, in place of the videos and the standards chips', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-featured.*?</section>#s', $html, $section);

    expect($section[0] ?? '')->toMatch('#<h2[^>]*id="home-featured"[^>]*>Product Spotlight</h2>#')
        ->and($section[0])->not->toContain('<video')
        ->and(substr_count($section[0], '<img'))->toBe(1)
        ->and($section[0])->toContain('images/home/cador-colours-that-work.jpg')
        ->and($section[0])->toContain('images/home/cador-colours-that-work.webp')
        ->and($section[0])->toContain('width="1200"')
        ->and($section[0])->toContain('height="1563"')
        ->and(file_exists(public_path('images/home/cador-colours-that-work.jpg')))->toBeTrue()
        ->and($html)->not->toContain('data-home-standards');
});

it('only picks products from the Safety Footwear category', function (): void {
    $categoryId = array_search(CollectionPath::fromSegments('safety-footwear'), (new CollectionPath)->all(), true);
    $picks = (new HomeFeaturedProducts)->pick(100);

    expect($categoryId)->not->toBeFalse()->and($picks)->not->toBeEmpty();

    foreach ($picks as $product) {
        $ids = $product->collections->flatMap(fn ($collection) => $collection->ancestors->pluck('id')->push($collection->id));

        expect($ids->contains($categoryId))->toBeTrue();
    }
});

it('styles the industries heading like the others, 20% larger, with a left aligned intro', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    $start = (int) strpos($html, '[&_h2]:text-[1.8rem]');
    $block = substr($html, $start, (int) strpos($html, '</h3>', $start) - $start);

    expect($start)->toBeGreaterThan(0)
        ->and($block)->toContain('sm:[&_h2]:text-[2.25rem]')
        ->and($block)->toContain('h-1 w-12') // the teal rule, like the other headings
        ->and($block)->toContain('text-[1.2rem]')
        ->and($block)->not->toContain('text-center')
        ->and($block)->not->toContain('mx-auto');
});

it('puts an intro h3 under the industries heading and a light grey band above the footer', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-service[^>]*>#', $html, $service);
    preg_match('#<section class="[^"]*"[^>]*data-home-service#', $html, $serviceClass);

    expect($html)->toMatch('#Solutions for every workplace</h2>.*?<h3[^>]*data-industries-intro[^>]*>Streamline your site procurement with a single, dependable UK trade supplier\. We stock certified head-to-toe PPE, workwear, and safety footwear ready for rapid nationwide dispatch and competitive bulk pricing\.</h3>#s')
        ->and($serviceClass[0] ?? '')->toContain('bg-surface-muted');
});

it('fades through all seven hero photos every six seconds, starting at a random one', function (): void {
    $hero = heroSection($this->get(route('home'))->getContent());

    preg_match_all('#<img[^>]*data-hero-slide[^>]*>#s', $hero, $slides);

    expect($slides[0])->toHaveCount(7)
        ->and($hero)->toContain('data-hero-slides')
        ->and($hero)->toContain('data-interval="6000"')
        ->and($hero)->toContain('}, 6000);')
        ->and($hero)->not->toContain('prefers-reduced-motion');

    // The first photo is in the page; the other six wait in data-src until their turn.
    expect($slides[0][0])->toContain(' src="')->toContain('data-hero-bg')->toContain('data-state="current"');

    $files = [];

    foreach ($slides[0] as $i => $img) {
        preg_match('#(?:data-src|(?<![-\w])src)="[^"]*hero-bg-([1-7])\.jpg"#', $img, $m);
        $files[] = (int) $m[1];

        if ($i > 0) {
            expect($img)->not->toMatch('#(?<![-\w])src="#')->toContain('data-state="idle"');
        }
    }

    // Every photo once, in a fixed cycle that starts wherever the page began.
    expect(collect($files)->sort()->values()->all())->toBe([1, 2, 3, 4, 5, 6, 7]);

    foreach ($files as $i => $n) {
        expect($files[($i + 1) % 7])->toBe($n % 7 + 1);
    }
});

it('fades the incoming photo in over the previous one so the background never dips to navy', function (): void {
    $hero = heroSection($this->get(route('home'))->getContent());

    expect($hero)->toContain('data-[state=current]:z-10')
        ->and($hero)->toContain('data-[state=current]:duration-[1500ms]')
        ->and($hero)->toContain('data-[state=previous]:opacity-100')
        ->and($hero)->not->toContain('motion-reduce:');
});
