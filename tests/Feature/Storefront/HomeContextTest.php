<?php

declare(strict_types=1);

use App\Domain\Catalogue\Home\HomeSpotlight;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Tests\Support\CatalogueFactory;
use Tests\Support\HomeSpotlights;

// The video section and the Product Spotlight on the home page used to be a bare video and a bare poster. They now
// have context: the video a heading, an intro, a caption and a link, and the Spotlight a feature block with the brand,
// the product's name, its own long description, the price (signed-in customers only) and a button to the product
// page. Both are driven by config/homepage.php. The wording comes from what the clips and posters say, and
// the client should confirm it.

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

/** The section of the home page marked with $marker, or an empty string. */
function homeSection(string $html, string $marker): string
{
    preg_match('#<section[^>]*'.$marker.'.*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

// --- The video section ------------------------------------------------------------------------

it('frames the video with a heading and an intro from the config', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();
    $section = homeSection($html, 'data-home-video');

    expect($section)->toContain('aria-labelledby="home-video"')
        ->toMatch('#<h2[^>]*id="home-video"[^>]*>See how it works</h2>#')
        ->toContain(config('homepage.video_section.intro'));

    config(['homepage.video_section' => ['title' => 'Watch and learn', 'intro' => 'A different intro.']]);

    expect(homeSection($this->get(route('home'))->getContent(), 'data-home-video'))
        ->toMatch('#<h2[^>]*id="home-video"[^>]*>Watch and learn</h2>#')
        ->toContain('A different intro.');
});

it('puts a caption and a link under the video, from the clip that is playing', function (): void {
    config(['homepage.videos' => [[
        'label' => 'Slip resistance',
        'src' => 'https://frontline-footwear.com/shop/datasheets/SLIP%20RESISTANCE_360p.mp4#t=0.5',
        'caption' => 'How slip resistance is built into safety footwear.',
        'link' => ['route' => 'technical-features', 'label' => 'See the technical features'],
    ]]]);

    $section = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-video');

    expect($section)->toContain('<figcaption')
        ->toContain('data-video-caption')
        ->toContain('How slip resistance is built into safety footwear.')
        ->toMatch('#<a href="'.preg_quote(route('technical-features'), '#').'"[^>]*data-video-link[^>]*>See the technical features#')
        // The caption is under the player, in the same figure.
        ->and(strpos($section, '<video'))->toBeLessThan(strpos($section, '<figcaption'));
});

it('falls back to the clip\'s label when it has no caption, and shows no link when it has none', function (): void {
    config(['homepage.videos' => [['label' => 'Taman', 'src' => 'https://frontline-footwear.com/shop/datasheets/TAMAN.mp4#t=0.5']]]);

    $section = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-video');

    expect($section)->toContain('Taman')->not->toContain('data-video-link');
});

it('has a real route behind every link in the video list', function (): void {
    foreach (config('homepage.videos') as $video) {
        if (isset($video['link'])) {
            expect(Route::has($video['link']['route']))->toBeTrue($video['label'].': '.$video['link']['route'])
                ->and($video['link']['label'])->not->toBe('');
        }
    }
});

it('rotates only the footwear and technology clips, and keeps the other four for when their categories launch', function (): void {
    $shown = array_column(config('homepage.videos'), 'src');
    $held = config('homepage.videos_held');

    expect(config('homepage.videos'))->toHaveCount(9)
        ->and($held)->toHaveCount(4)
        ->and(array_column($held, 'label'))->toBe(['Eye protection', 'Head protection', 'High visibility clothing', 'Work jackets'])
        ->and(array_intersect($shown, array_column($held, 'src')))->toBe([])
        ->and(array_unique(array_merge($shown, array_column($held, 'src'))))->toHaveCount(13);

    $labels = array_column($held, 'label');

    foreach (range(1, 40) as $ignored) {
        preg_match('#data-video-label="([^"]+)"#', $this->get(route('home'))->assertOk()->getContent(), $playing);

        expect($labels)->not->toContain(html_entity_decode($playing[1]));
    }
});

// --- The Product Spotlight --------------------------------------------------------------------

it('shows the Spotlight like the top of the product page: brand, name, title, short description, stock and a button', function (): void {
    [$one] = HomeSpotlights::seed();
    config(['homepage.spotlights' => [config('homepage.spotlights')[0]]]);

    $section = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured');

    expect($section)->toMatch('#<h2[^>]*id="home-featured"[^>]*>Product Spotlight</h2>#')
        ->toContain('data-spotlight>')
        ->toMatch('#data-spotlight-brand[^>]*>Test Brand<#')
        ->toMatch('#data-spotlight-name[^>]*>Spotlight Boot One<#')
        ->toMatch('#data-spotlight-title[^>]*>Spotlight Boot One Safety Boot<#')
        ->toMatch('#data-spotlight-short[^>]*>Short description of Spotlight Boot One\.<#')
        ->toMatch('#data-spotlight-stock[^>]*>In stock<#')
        // The long description is for the product page itself, not repeated here.
        ->not->toContain('First paragraph of')
        ->toMatch('#<a href="'.preg_quote(route('product', 'test-brand-spotlight-boot-one'), '#').'"[^>]*data-spotlight-link[^>]*>\s*View Spotlight Boot One#')
        ->and(substr_count($section, '<img'))->toBe(1)
        ->and($section)->toContain('alt="Campaign image 1"')->toContain('width="1200"')->toContain('loading="lazy"');

    expect($one->translateAttribute('name'))->toBe('Spotlight Boot One');
});

it('shows the colours and sizes as the product page does: colours open the product on that colour, an out of stock colour is struck out', function (): void {
    HomeSpotlights::seed();
    config(['homepage.spotlights' => [config('homepage.spotlights')[0]]]);

    $section = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured');

    preg_match('#data-option="colour".*?</ul>#s', $section, $colour);
    preg_match('#data-option="shoe_size".*?</ul>#s', $section, $size);

    $product = route('product', 'test-brand-spotlight-boot-one');

    // Colour: the one in stock is selected and links to its colour on the product page.
    expect($colour[0])->toContain('Colour:')->toContain('Black')
        ->toMatch('#<a\s+href="'.preg_quote($product.'?colour=black', '#').'"[^>]*aria-current="true"[^>]*data-spotlight-colour#')
        // Brown has none in stock: shown, struck out, and not a link.
        ->toMatch('#data-value="brown">\s*<span[^>]*line-through#')
        ->and(substr_count($colour[0], '<a '))->toBe(1);

    // Size: both shown, with the size guide, and chosen on the product page.
    expect($size[0])->toContain('Size')->toContain('8')->toContain('9')
        ->toContain('href="'.route('size-guide').'"')
        ->and(substr_count($size[0], '<a '))->toBe(1);
});

it('shows an option with a single value as a plain line, like the product page', function (): void {
    $product = CatalogueFactory::product('Spotlight Single', [['colour' => 'Black', 'size' => 'One Size', 'stock' => 4]]);
    config(['homepage.spotlights' => [['slug' => 'test-brand-spotlight-single', 'file' => 'maximus-helmet', 'webp' => false, 'alt' => 'x', 'width' => 1200, 'height' => 1508]]]);

    $section = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured');

    expect($section)->toMatch('#<p data-option="colour" data-fixed>\s*<span class="font-semibold">Colour:</span>\s*Black#')
        ->toMatch('#<p data-option="shoe_size" data-fixed>\s*<span class="font-semibold">Size:</span>\s*One Size#')
        ->toContain('href="'.route('size-guide').'"')
        ->and($product->translateAttribute('name'))->toBe('Spotlight Single');
});

it('says out of stock when no variant has any', function (): void {
    $products = HomeSpotlights::seed();
    config(['homepage.spotlights' => [config('homepage.spotlights')[0]]]);

    $products[0]->variants()->update(['stock' => 0]);

    expect(homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured'))
        ->toMatch('#data-spotlight-stock[^>]*>Out of stock<#');
});

it('leaves out the title and short description lines the product does not have, and keeps the rest', function (): void {
    [$one] = HomeSpotlights::seed();
    config(['homepage.spotlights' => [config('homepage.spotlights')[0]]]);

    $one->update(['attribute_data' => $one->attribute_data->merge([
        'title' => new TranslatedText(['en' => new Text('')]),
        'short_description' => new TranslatedText(['en' => new Text('')]),
    ])]);

    $section = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured');

    expect($section)->not->toContain('data-spotlight-title')->not->toContain('data-spotlight-short')
        ->toContain('data-spotlight-name')->toContain('data-spotlight-link');
});

it('escapes what the catalogue says, so its text can never add markup to the home page', function (): void {
    [$one] = HomeSpotlights::seed();
    config(['homepage.spotlights' => [config('homepage.spotlights')[0]]]);

    $one->update(['attribute_data' => $one->attribute_data->merge(['short_description' => new TranslatedText(['en' => new Text('Tough <b>boot</b> & <script>alert(1)</script>')])])]);

    $section = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured');

    expect($section)->toContain('Tough &lt;b&gt;boot&lt;/b&gt; &amp; &lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<b>boot')
        ->not->toContain('<script');
});

it('offers Order now to a signed-in customer, on the colour shown, and Log in to order to a guest, with View product for both', function (): void {
    HomeSpotlights::seed();
    config(['homepage.spotlights' => [config('homepage.spotlights')[0]]]);

    $product = route('product', 'test-brand-spotlight-boot-one');

    $guest = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured');

    expect($guest)->toMatch('#<a href="'.preg_quote(route('login'), '#').'"[^>]*data-spotlight-order[^>]*>\s*Log in to order#')
        ->not->toContain('Order now')
        ->toMatch('#<a href="'.preg_quote($product, '#').'"[^>]*data-spotlight-link[^>]*>\s*View Spotlight Boot One#');

    $this->actingAs(User::factory()->create(), 'web');

    $member = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured');

    // The product page opens on the colour the Spotlight is showing, ready for a size and a quantity.
    expect($member)->toMatch('#<a href="'.preg_quote($product.'?colour=black', '#').'"[^>]*data-spotlight-order[^>]*>\s*Order now#')
        ->not->toContain('Log in to order')
        ->toMatch('#<a href="'.preg_quote($product, '#').'"[^>]*data-spotlight-link[^>]*>\s*View Spotlight Boot One#');
});

it('picks one campaign at random on each page load, each from the config', function (): void {
    HomeSpotlights::seed();
    $seen = [];

    foreach (range(1, 40) as $ignored) {
        preg_match('#data-spotlight-name[^>]*>([^<]+)<#', $this->get(route('home'))->assertOk()->getContent(), $name);
        $seen[$name[1]] = true;
    }

    expect(array_keys($seen))->each->toBeIn(['Spotlight Boot One', 'Spotlight Boot Two', 'Spotlight Boot Three'])
        ->and(count($seen))->toBeGreaterThan(1);
});

it('leaves out a campaign whose product is not published or not in the catalogue', function (): void {
    [$one, $two, $three] = HomeSpotlights::seed();
    $two->update(['status' => 'draft']);

    $available = array_map(fn (array $pick): string => (string) $pick['product']->translateAttribute('name'), app(HomeSpotlight::class)->available());

    expect($available)->toBe(['Spotlight Boot One', 'Spotlight Boot Three']);

    // A campaign with no product at all.
    config(['homepage.spotlights' => array_merge(config('homepage.spotlights'), [['slug' => 'no-such-product', 'file' => 'maximus-helmet', 'webp' => false, 'alt' => 'x', 'width' => 1200, 'height' => 1508]])]);

    expect(app(HomeSpotlight::class)->available())->toHaveCount(2);
});

it('does not show the section, and does not break the page, when no campaign has a published product', function (): void {
    HomeSpotlights::seed('draft');

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->not->toContain('data-home-featured')->not->toContain('Product Spotlight')->not->toContain('data-spotlight>')
        ->and($html)->toContain('data-home-service');

    config(['homepage.spotlights' => []]);

    $this->get(route('home'))->assertOk()->assertDontSee('data-home-featured', false);
});

it('shows the price to a signed-in customer only, like every other price', function (): void {
    HomeSpotlights::seed();
    config(['homepage.spotlights' => [config('homepage.spotlights')[0]]]);

    $guest = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured');

    expect($guest)->not->toContain('data-price')->not->toContain('£');

    $this->actingAs(User::factory()->create(), 'web');

    $member = homeSection($this->get(route('home'))->assertOk()->getContent(), 'data-home-featured');

    expect($member)->toContain('data-price')->toContain('£50.00')->toContain('ex VAT');
});

it('keeps the order of the page: video, featured products, spotlight, service strip', function (): void {
    HomeSpotlights::seed();

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(strpos($html, 'data-home-industries'))->toBeLessThan(strpos($html, 'data-home-video'))
        ->and(strpos($html, 'data-home-video'))->toBeLessThan(strpos($html, 'data-home-products'))
        ->and(strpos($html, 'data-home-products'))->toBeLessThan(strpos($html, 'data-home-featured'))
        ->and(strpos($html, 'data-home-featured'))->toBeLessThan(strpos($html, 'data-home-service'));
});

it('has the three campaign images on disk, each with a slug and alt text, and no two alike', function (): void {
    $spotlights = require config_path('homepage.php');
    $spotlights = $spotlights['spotlights'];

    expect($spotlights)->toHaveCount(3)
        ->and(array_unique(array_column($spotlights, 'slug')))->toHaveCount(3)
        ->and(array_unique(array_column($spotlights, 'file')))->toHaveCount(3);

    foreach ($spotlights as $spotlight) {
        expect(public_path('images/home/'.$spotlight['file'].'.jpg'))->toBeFile()
            ->and($spotlight)->not->toHaveKeys(['strapline', 'points'])
            ->and($spotlight['alt'])->not->toBe('');

        if ($spotlight['webp']) {
            expect(public_path('images/home/'.$spotlight['file'].'.webp'))->toBeFile();
        }
    }
});

// --- Copy rules -------------------------------------------------------------------------------

it('uses UK English and no em dashes in the new copy', function (): void {
    foreach (['config/homepage.php', 'resources/views/components/home/video-banner.blade.php', 'resources/views/components/home/featured-products.blade.php', 'app/Domain/Catalogue/Home/HomeSpotlight.php', 'app/View/Components/Home/FeaturedProducts.php'] as $file) {
        $text = (string) file_get_contents(base_path($file));

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(organization|license|favorite|analyze|behavior)\b/i');
    }
});
