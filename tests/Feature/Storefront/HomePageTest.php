<?php

declare(strict_types=1);

use App\Domain\Catalogue\Home\HeroImage;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Language;
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

function tileCategory(string $name, string $slug): Collection
{
    $collection = Collection::create([
        'collection_group_id' => CollectionGroup::query()->where('handle', 'categories')->sole()->id,
        'attribute_data' => ['name' => new TranslatedText(['en' => new Text($name)])],
    ], CatalogueKeys::collection('safety-footwear'));

    $collection->urls()->create(['slug' => $slug, 'default' => true, 'language_id' => Language::getDefault()->id]);

    return $collection;
}

function tilesOf(string $html): string
{
    preg_match('#<section[^>]*data-home-categories.*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

it('shows a tile for each child of safety footwear, each one a single link', function (): void {
    $html = tilesOf($this->get(route('home'))->assertOk()->getContent());

    foreach (['safety-trainers' => 'Safety Trainers', 'safety-shoes' => 'Safety Shoes', 'safety-boots' => 'Safety Boots'] as $slug => $name) {
        expect($html)->toContain('href="'.route('category', 'safety-footwear/'.$slug).'"')
            ->and($html)->toContain('aria-label="Shop '.$name.'"');
    }

    expect(substr_count($html, 'data-category-tile'))->toBe(3)
        ->and($html)->not->toContain('All products')
        ->and($html)->toMatch('#<h2[^>]*id="home-categories"#');
});

it('builds tiles from the collection tree, so a new child collection appears', function (): void {
    tileCategory('Safety Sandals', 'safety-sandals');

    $html = tilesOf($this->get(route('home'))->getContent());

    expect(substr_count($html, 'data-category-tile'))->toBe(4)
        ->and($html)->toContain('href="'.route('category', 'safety-footwear/safety-sandals').'"')
        ->and($html)->toContain('aria-label="Shop Safety Sandals"');
});

it('uses the first published product with an image, lazy loaded with its size set', function (): void {
    $product = Url::query()->where('slug', 'safety-jogger-ligero')->sole()->element;
    $collection = tileCategory('Safety Sandals', 'safety-sandals');
    $collection->products()->attach($product->id, ['position' => 1]);

    $html = tilesOf($this->get(route('home'))->getContent());
    preg_match('#<a\s+href="'.preg_quote(route('category', 'safety-footwear/safety-sandals'), '#').'".*?</a>#s', $html, $tile);

    expect($tile[0])->toContain('<img')
        ->and($tile[0])->toContain('loading="lazy"')
        ->and($tile[0])->toContain('width="800"')
        ->and($tile[0])->toContain('height="800"')
        ->and($tile[0])->toContain('srcset=');
});

it('skips draft products and products with no image, and falls back to a plain tile', function (): void {
    $product = Url::query()->where('slug', 'safety-jogger-ligero')->sole()->element;
    $collection = tileCategory('Safety Sandals', 'safety-sandals');
    $collection->products()->attach($product->id, ['position' => 1]);

    $product->update(['status' => 'draft']);

    $tile = fn (): string => (function (string $html): string {
        preg_match('#<a\s+href="'.preg_quote(route('category', 'safety-footwear/safety-sandals'), '#').'".*?</a>#s', $html, $m);

        return $m[0];
    })(tilesOf($this->get(route('home'))->getContent()));

    expect($tile())->not->toContain('<img')->and($tile())->toContain('Safety Sandals');

    $product->update(['status' => 'published']);
    $product->clearMediaCollection(config('lunar.media.collection'));

    expect($tile())->not->toContain('<img');
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
