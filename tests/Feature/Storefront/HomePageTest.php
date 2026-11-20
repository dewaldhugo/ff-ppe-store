<?php

declare(strict_types=1);

use App\Domain\Catalogue\Home\HeroImage;
use App\Domain\Catalogue\Home\HomeFeaturedProducts;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Product;
use Lunar\Models\Url;
use Tests\Support\CatalogueFactory;

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

it('links the primary button to the shop and the secondary button to registration, with the same corner radius', function (): void {
    $hero = heroSection($this->get(route('home'))->getContent());

    expect($hero)->toMatch('#<a href="'.preg_quote(route('shop'), '#').'"[^>]*rounded-md[^>]*bg-brand-blue[^>]*text-white[^>]*>View all products</a>#')
        ->and($hero)->not->toMatch('#<a href="'.preg_quote(route('shop'), '#').'"[^>]*bg-brand-teal[^>]*>View all products</a>#')
        ->and($hero)->toMatch('#<a href="'.preg_quote(route('register'), '#').'"[^>]*rounded-md[^>]*border-brand-teal[^>]*>Open a trade account</a>#')
        ->and($hero)->not->toContain('rounded-full');
});

it('pins the View product button to the bottom of every card, so cards match in height with no price shown', function (string $page): void {
    // A guest sees no price, which is what used to leave the buttons floating.
    $html = $this->get($page === 'home' ? route('home') : route('category', 'safety-footwear'))->assertOk()->getContent();

    // Blade renders the attribute as data-view-product="data-view-product", so count the buttons, not the word.
    $cards = preg_match_all('#<span[^>]*data-view-product#', $html);

    expect($cards)->toBeGreaterThan(0)
        ->and(preg_match_all('#<div class="mt-auto flex flex-col">(?:\s|<!--.*?-->)*(?:<p[^>]*data-price.*?</p>(?:\s|<!--.*?-->)*)?<span[^>]*data-view-product#s', $html))->toBe($cards);
})->with(['home', 'listing']);

it('shows one autoplaying, muted video across the full width of the container, above the featured products', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-video.*?</section>#s', $html, $section);
    preg_match('#<video[^>]*>#s', $section[0] ?? '', $video);
    preg_match('#<source[^>]*src="([^"]+)"[^>]*type="video/mp4"#', $section[0] ?? '', $source);

    expect($section)->not->toBeEmpty()
        ->and(substr_count($html, '<video'))->toBe(1)
        ->and(array_column(config('homepage.videos'), 'src'))->toContain(html_entity_decode($source[1]))
        ->and($video[0])->toMatch('/\sautoplay\s/')
        ->and($video[0])->toMatch('/\smuted\s/')
        ->and($video[0])->toMatch('/\splaysinline\s/')
        ->and($video[0])->toMatch('/\scontrols\s/')
        ->and($video[0])->toContain('w-full')
        // The width of the page container (the same one as the sections around it), not the screen.
        ->and($section[0])->toContain('max-w-7xl')
        // Directly above the Featured products section.
        ->and(strpos($html, 'data-home-video'))->toBeLessThan(strpos($html, 'data-home-products'));
});

it('has the thirteen videos, each a distinct https mp4 with a label', function (): void {
    $videos = config('homepage.videos');

    expect($videos)->toHaveCount(13)
        ->and(array_unique(array_column($videos, 'src')))->toHaveCount(13)
        ->and(array_unique(array_column($videos, 'label')))->toHaveCount(13);

    foreach ($videos as $video) {
        expect($video['src'])->toStartWith('https://frontline-footwear.com/shop/datasheets/')
            ->and($video['src'])->toMatch('/\.mp4(#t=0\.5)?$/')
            ->and($video['label'])->not->toBe('');
    }
});

it('picks a different video from page load to page load, and each one is from the list', function (): void {
    $labels = array_column(config('homepage.videos'), 'label');
    $seen = [];

    foreach (range(1, 60) as $ignored) {
        preg_match('#data-video-label="([^"]+)"#', $this->get(route('home'))->assertOk()->getContent(), $m);

        expect($labels)->toContain(html_entity_decode($m[1]));
        $seen[$m[1]] = true;
    }

    expect(count($seen))->toBeGreaterThan(3);
});

it('gives a video its own poster, loop and preload only when the list says so', function (): void {
    config(['homepage.videos' => [[
        'label' => 'CADOR colours that work',
        'src' => 'https://frontline-footwear.com/shop/datasheets/CADOR%20Colours%20that%20work.mp4',
        'poster' => 'https://frontline-footwear.com/v1.jpg',
        'loop' => true,
        'preload' => 'metadata',
    ]]]);

    preg_match('#<video[^>]*>#s', $this->get(route('home'))->getContent(), $with);

    expect($with[0])->toMatch('/\sloop\s/')
        ->and($with[0])->toContain('poster="https://frontline-footwear.com/v1.jpg"')
        ->and($with[0])->toContain('preload="metadata"')
        ->and($with[0])->toContain('aria-label="CADOR colours that work"');

    config(['homepage.videos' => [['label' => 'Midsoles', 'src' => 'https://frontline-footwear.com/shop/datasheets/MIDSOLES_360p.mp4#t=0.5']]]);

    preg_match('#<video[^>]*>#s', $this->get(route('home'))->getContent(), $without);

    expect($without[0])->not->toMatch('/\sloop\s/')->and($without[0])->not->toContain('poster=');
});

it('shows no video section when the list is empty', function (): void {
    config(['homepage.videos' => []]);

    $this->get(route('home'))->assertOk()->assertDontSee('data-home-video', false);
});

it('shows Open a trade account to guests only', function (): void {
    expect(heroSection($this->get(route('home'))->getContent()))->toContain('Open a trade account');

    $this->actingAs(User::factory()->create(), 'web');

    $hero = heroSection($this->get(route('home'))->assertOk()->getContent());

    expect($hero)->not->toContain('Open a trade account')
        ->and($hero)->toContain('View all products');
});

it('uses one of the six hero-bg photos in the slideshow as the hero background, centred, with no overlay', function (): void {
    foreach ([1, 2, 7, 8, 9, 10] as $n) {
        expect(is_file(public_path("images/hero-bg-{$n}.jpg")))->toBeTrue();
    }

    $seen = [];

    foreach (range(1, 40) as $ignored) {
        $hero = heroSection($this->get(route('home'))->getContent());

        expect($hero)->toMatch('#<img[^>]*src="'.preg_quote(asset('images/hero-bg-'), '#').'\d+\.jpg"[^>]*data-hero-bg#')
            ->and($hero)->toContain('alt=""')
            ->and($hero)->toContain('width="1920"')
            ->and($hero)->toContain('height="882"')
            ->and($hero)->toContain('object-cover object-center')
            ->and($hero)->not->toContain('object-[center_')
            ->and($hero)->not->toContain('data-hero-overlay')
            ->and($hero)->not->toContain('from-brand-navy/');

        preg_match('#hero-bg-(\d+)\.jpg#', $hero, $m);
        expect([1, 2, 7, 8, 9, 10])->toContain((int) $m[1]); // 3, 4, 5 and 6 are out of the slideshow
        $seen[$m[1]] = true;
    }

    // Random per page load: 40 loads showing a single image has odds of 6^-39.
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
    $this->actingAs(User::factory()->create(), 'web'); // the cards show a price only to signed-in customers

    $html = $this->get(route('home'))->assertOk()->getContent();
    $section = productPicksOf($html);
    $cards = substr_count($section, 'data-home-product>');

    expect($html)->not->toContain('data-home-categories')
        ->and($html)->not->toContain('Shop by type')
        ->and($section)->toMatch('#<h2[^>]*id="home-products"[^>]*>Featured products</h2>#')
        ->and($cards)->toBeGreaterThan(0)
        ->and($cards)->toBeLessThanOrEqual(8);

    preg_match_all('#<li[^>]*data-home-product>.*?</li>#s', $section, $items);

    foreach ($items[0] as $item) {
        expect($item)->toContain('href="'.url('/products/')) // a product link, built from route('product')
            ->and($item)->toContain('loading="lazy"')
            ->and($item)->toContain('width="300"')
            ->and($item)->toContain('height="300"')
            ->and($item)->toContain('data-price');
    }
});

it('shows eight products when the catalogue has at least eight that can be shown', function (): void {
    $eligible = (new HomeFeaturedProducts)->pick(100)->count();

    $shown = substr_count(productPicksOf($this->get(route('home'))->getContent()), 'data-home-product>');

    expect($shown)->toBe(min(8, $eligible));
});

it('picks a different set of products from page load to page load', function (): void {
    $eligible = (new HomeFeaturedProducts)->pick(100)->count();

    if ($eligible <= 8) {
        $this->markTestSkipped('The fixture catalogue has eight or fewer products to pick from.');
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

it('shows the Product Spotlight as one image picked at random from three, in place of the videos and the standards chips', function (): void {
    $files = ['cador-colours-that-work', 'topskate-s3s-safety-sneaker', 'maximus-helmet'];
    $seen = [];

    foreach (range(1, 40) as $ignored) {
        $html = $this->get(route('home'))->assertOk()->getContent();

        preg_match('#<section[^>]*data-home-featured.*?</section>#s', $html, $section);

        expect($section[0] ?? '')->toMatch('#<h2[^>]*id="home-featured"[^>]*>Product Spotlight</h2>#')
            ->and($section[0])->not->toContain('<video')
            ->and(substr_count($section[0], '<img'))->toBe(1)
            ->and($section[0])->toContain('width="1200"')
            ->and($html)->not->toContain('data-home-standards');

        preg_match('#images/home/([a-z0-9-]+)\.jpg#', $section[0], $m);
        expect($files)->toContain($m[1]);
        $seen[$m[1]] = true;
    }

    foreach ($files as $file) {
        expect(file_exists(public_path("images/home/{$file}.jpg")))->toBeTrue();
    }

    expect(file_exists(public_path('images/home/cador-colours-that-work.webp')))->toBeTrue()
        ->and(count($seen))->toBeGreaterThan(1);
});

it('only picks products from the Safety Footwear and PPE & Gear categories', function (): void {
    $allowed = collect(HomeFeaturedProducts::CATEGORIES)
        ->flatMap(fn (string $handle) => CatalogueKeys::collection($handle)->descendants->pluck('id')->push(CatalogueKeys::collection($handle)->id))
        ->all();

    $picks = (new HomeFeaturedProducts)->pick(100);

    expect($picks)->not->toBeEmpty();

    foreach ($picks as $product) {
        expect($product->collections->pluck('id')->intersect($allowed))->not->toBeEmpty();
    }
});

/**
 * Leaves exactly these products eligible for the home page: $footwear published, priced, imaged products
 * in Safety Footwear and $gear in PPE & Gear, and every other product a draft. The fixture catalogue is
 * too small to test the 4 + 4 pick, so these are made here.
 *
 * @return array{footwear: list<int>, gear: list<int>}
 */
function homePickCatalogue(int $footwear, int $gear): array
{
    Storage::fake('products');

    $image = imagecreatetruecolor(40, 40);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $made = ['footwear' => [], 'gear' => []];

    foreach (['footwear' => [$footwear, 'safety-footwear'], 'gear' => [$gear, 'ppe-gear']] as $key => [$count, $handle]) {
        for ($i = 1; $i <= $count; $i++) {
            $product = CatalogueFactory::product("Pick {$key} {$i}", [['stock' => 5]], 'published', 5000);
            $product->collections()->attach(CatalogueKeys::collection($handle)->id, ['position' => 1]);
            $product->addMediaFromString($png)->usingFileName("{$key}-{$i}.png")->toMediaCollection(config('lunar.media.collection'));
            $made[$key][] = $product->id;
        }
    }

    Product::query()->whereNotIn('id', [...$made['footwear'], ...$made['gear']])->update(['status' => 'draft']);

    return $made;
}

it('shows four products from Safety Footwear and four from PPE & Gear, eight in all, with none repeated', function (): void {
    $made = homePickCatalogue(footwear: 6, gear: 6);

    foreach (range(1, 25) as $ignored) {
        $pick = (new HomeFeaturedProducts)->pick();
        $keys = $pick->modelKeys();

        expect($pick)->toHaveCount(8)
            ->and($keys)->toBe(array_values(array_unique($keys)))
            ->and(count(array_intersect($keys, $made['footwear'])))->toBe(4)
            ->and(count(array_intersect($keys, $made['gear'])))->toBe(4);
    }
});

it('takes four from each category from different products over time', function (): void {
    $made = homePickCatalogue(footwear: 8, gear: 8);
    $footwearSeen = [];
    $gearSeen = [];

    foreach (range(1, 40) as $ignored) {
        $keys = (new HomeFeaturedProducts)->pick()->modelKeys();
        $footwearSeen = [...$footwearSeen, ...array_intersect($keys, $made['footwear'])];
        $gearSeen = [...$gearSeen, ...array_intersect($keys, $made['gear'])];
    }

    // Eight to choose from, four taken each time: over 40 loads more than four different ones appear.
    expect(count(array_unique($footwearSeen)))->toBeGreaterThan(4)
        ->and(count(array_unique($gearSeen)))->toBeGreaterThan(4);
});

it('shows the eight in random order, so footwear does not always come first', function (): void {
    $made = homePickCatalogue(footwear: 4, gear: 4);
    $firstIsFootwear = [];
    $orders = [];

    foreach (range(1, 40) as $ignored) {
        $keys = (new HomeFeaturedProducts)->pick()->modelKeys();
        $firstIsFootwear[] = in_array($keys[0], $made['footwear'], true);
        $orders[implode(',', $keys)] = true;
    }

    // Always the same eight products, but both kinds lead the row at times and the order keeps changing.
    expect(array_unique($firstIsFootwear))->toHaveCount(2)
        ->and(count($orders))->toBeGreaterThan(5);
});

it('fills the gap from the other category when PPE & Gear has too few products', function (): void {
    $made = homePickCatalogue(footwear: 8, gear: 1);

    $keys = (new HomeFeaturedProducts)->pick()->modelKeys();

    expect($keys)->toHaveCount(8)
        ->and($keys)->toBe(array_values(array_unique($keys)))
        ->and(count(array_intersect($keys, $made['gear'])))->toBe(1)
        ->and(count(array_intersect($keys, $made['footwear'])))->toBe(7);
});

it('shows fewer than eight, never padding, when the catalogue has fewer to show', function (): void {
    homePickCatalogue(footwear: 2, gear: 1);

    expect((new HomeFeaturedProducts)->pick())->toHaveCount(3);
});

it('shows each product once when it is in both categories', function (): void {
    $made = homePickCatalogue(footwear: 5, gear: 5);
    $shared = Product::query()->findOrFail($made['footwear'][0]);
    $shared->collections()->attach(CatalogueKeys::collection('ppe-gear')->id, ['position' => 2]);

    foreach (range(1, 20) as $ignored) {
        $keys = (new HomeFeaturedProducts)->pick()->modelKeys();

        expect($keys)->toBe(array_values(array_unique($keys)));
    }
});

it('leaves out a product that is in neither category, and takes one from PPE & Gear', function (): void {
    $product = Url::query()->where('slug', 'safety-jogger-ligero')->sole()->element;
    $picks = new HomeFeaturedProducts;

    // Moved to Apparel: not eligible.
    $product->collections()->detach();
    $product->collections()->attach(CatalogueKeys::collection('apparel')->id, ['position' => 1]);

    expect($picks->pick(100)->modelKeys())->not->toContain($product->id);

    // Moved to a PPE & Gear sub-category: eligible.
    $product->collections()->detach();
    $product->collections()->attach(CatalogueKeys::collection('gloves')->id, ['position' => 1]);

    expect($picks->pick(100)->modelKeys())->toContain($product->id);
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

it('fades through the six hero photos in use every six seconds, starting at a random one', function (): void {
    $hero = heroSection($this->get(route('home'))->getContent());

    preg_match_all('#<img[^>]*data-hero-slide[^>]*>#s', $hero, $slides);

    expect($slides[0])->toHaveCount(6)
        ->and($hero)->toContain('data-hero-slides')
        ->and($hero)->toContain('data-interval="6000"')
        ->and($hero)->toContain('}, 6000);')
        ->and($hero)->not->toContain('prefers-reduced-motion');

    expect($hero)->not->toContain('hero-bg-3.jpg')
        ->and($hero)->not->toContain('hero-bg-4.jpg')
        ->and($hero)->not->toContain('hero-bg-5.jpg')
        ->and($hero)->not->toContain('hero-bg-6.jpg');

    // The first photo is in the page; the other five wait in data-src until their turn.
    expect($slides[0][0])->toContain(' src="')->toContain('data-hero-bg')->toContain('data-state="current"');

    $files = [];

    foreach ($slides[0] as $i => $img) {
        preg_match('#(?:data-src|(?<![-\w])src)="[^"]*hero-bg-(\d+)\.jpg"#', $img, $m);
        $files[] = (int) $m[1];

        if ($i > 0) {
            expect($img)->not->toMatch('#(?<![-\w])src="#')->toContain('data-state="idle"');
        }
    }

    // Every photo once, in a fixed cycle that starts wherever the page began.
    expect(collect($files)->sort()->values()->all())->toBe([1, 2, 7, 8, 9, 10]);

    foreach ($files as $i => $n) {
        expect($files[($i + 1) % 6])->toBe([1 => 2, 2 => 7, 7 => 8, 8 => 9, 9 => 10, 10 => 1][$n]);
    }
});

it('fades the incoming photo in over the previous one so the background never dips to navy', function (): void {
    $hero = heroSection($this->get(route('home'))->getContent());

    expect($hero)->toContain('data-[state=current]:z-10')
        ->and($hero)->toContain('data-[state=current]:duration-[1500ms]')
        ->and($hero)->toContain('data-[state=previous]:opacity-100')
        ->and($hero)->not->toContain('motion-reduce:');
});
