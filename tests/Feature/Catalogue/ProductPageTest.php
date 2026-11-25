<?php

declare(strict_types=1);

use App\Domain\Catalogue\Media\PlaceholderImageGenerator;
use App\Livewire\Catalogue\ProductPage;
use App\Models\User;
use Database\Seeders\FixtureCatalogueSeeder;
use Database\Seeders\StorefrontBrandSeeder;
use Livewire\Livewire;
use Lunar\FieldTypes\ListField;
use Lunar\FieldTypes\Text;
use Lunar\Models\Product;
use Lunar\Models\Url;
use Meilisearch\Client;
use Tests\Support\CatalogueFactory;
use Tests\Support\SearchIndex;

// Only signed-in customers see prices (tests/Feature/Storefront/PriceGatingTest.php covers guests).
beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'web');
});

/**
 * @return array<string, mixed>
 */
function structuredData(string $html): array
{
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    expect($matches)->not->toBeEmpty();

    return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The `data-colour` of every gallery image on the page.
 *
 * @return list<string>
 */
function galleryColours(string $html): array
{
    preg_match_all('/data-colour="([^"]*)"/', $html, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * Size buttons on the page that are not disabled.
 *
 * @return list<string>
 */
function enabledSizes(string $html): array
{
    preg_match('#<fieldset[^>]*data-option="shoe_size".*?</fieldset>#s', $html, $fieldset);
    preg_match_all('#<button(?![^>]*\sdisabled)[^>]*data-value="([^"]+)"#s', $fieldset[0] ?? '', $matches);

    return $matches[1];
}

it('shows a breadcrumb: Products, the category path, then the product as the current page, with no Home', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<nav aria-label="Breadcrumb".*?</nav>#s', $html, $nav);
    // Livewire adds <!--[if BLOCK]--> markers around @if blocks, so skip comments between the tags.
    preg_match_all('#<li([^>]*)>\s*(?:<!--.*?-->\s*)*(?:<a href="([^"]+)"[^>]*>([^<]+)</a>|<span[^>]*>([^<]+)</span>)#s', $nav[0], $items, PREG_SET_ORDER);

    $trail = array_map(fn (array $item): array => [$item[3] !== '' ? $item[3] : $item[4], $item[2] ?: null], $items);

    expect($trail)->toBe([
        ['Products', route('shop')],
        ['Safety Footwear', route('category', 'safety-footwear')],
        ['Safety Shoes', route('category', 'safety-footwear/safety-shoes')],
        ['LIGERO', null],
    ])
        // The current page is text, marked aria-current, and it is the last item.
        ->and(substr_count($nav[0], 'aria-current="page"'))->toBe(1)
        ->and($items[3][1])->toContain('aria-current="page"')
        // The breadcrumb sits above the product heading.
        ->and(strpos($html, 'aria-label="Breadcrumb"'))->toBeLessThan(strpos($html, 'id="product-heading"'));
});

it('shows the product with its price, description and specification', function (): void {
    $this->get(route('product', 'safety-jogger-ligero'))
        ->assertOk()
        ->assertSee('LIGERO')
        ->assertSee('Lightweight ESD Safety Shoes')
        ->assertSee('Ultra-light, flexible safety shoe')
        ->assertSee('£68.90')
        ->assertSee('ex VAT')
        ->assertSee('Slip resistant (SRC)')
        ->assertSee('ESD, electrostatic dissipative')
        ->assertSee('Metal free')
        ->assertSee('Nano Carbon')
        ->assertSee('EN ISO 20345:2011')
        ->assertSee('https://www.youtube.com/embed/fux4KhcHMLw')
        ->assertSee('EU 42');
});

it('returns 404 for an unknown or draft product', function (): void {
    $this->get(route('product', 'does-not-exist'))->assertNotFound();

    CatalogueFactory::product('Hidden', [['stock' => 1]], status: 'draft');

    $this->get(route('product', 'test-brand-hidden'))->assertNotFound();
});

it('pre-selects the first in-stock colour and puts it in the query string', function (): void {
    Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero'])
        ->assertSet('colour', 'navy')
        ->assertSet('choices', [])
        ->assertSee('Colour')
        ->assertDontSee('Please select');
});

it('restores the colour from the query string', function (): void {
    Livewire::withQueryParams(['colour' => 'black'])
        ->test(ProductPage::class, ['slug' => 'safety-jogger-ligero'])
        ->assertSet('colour', 'black');
});

it('shows only black images and the sizes available in black when Black is selected', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero').'?colour=black')->assertOk()->getContent();

    expect(galleryColours($html))->toBe(['Black'])
        ->and(enabledSizes($html))->toBe(['3.5', '4', '5', '6', '6.5', '7', '8', '9', '10.5', '11', '12'])
        ->and($html)->toContain('data-value="10"');

    $navy = $this->get(route('product', 'safety-jogger-ligero').'?colour=navy')->getContent();

    expect(galleryColours($navy))->toBe(['Navy'])
        ->and(enabledSizes($navy))->toContain('10');
});

it('switches the gallery when a colour is chosen', function (): void {
    $component = Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero'])
        ->call('choose', 'colour', 'orange')
        ->assertSet('colour', 'orange');

    expect(galleryColours($component->html()))->toBe(['Orange']);
});

it('highlights the missing option when adding to cart without a size', function (): void {
    Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero'])
        ->call('addToCart')
        ->assertSet('missingOption', 'shoe_size')
        ->assertSet('added', false)
        ->assertSee('Please select a size')
        ->assertSeeHtml('role="alert"')
        ->call('choose', 'shoe_size', '8')
        ->assertSet('missingOption', null)
        ->assertDontSee('Please select a size')
        ->set('quantity', '2')
        ->call('addToCart')
        ->assertSet('added', true);
});

it('names the colour when the colour is what is missing', function (): void {
    CatalogueFactory::product('Sold Out', [
        ['colour' => 'Test Red', 'size' => '6', 'stock' => 0],
        ['colour' => 'Test Green', 'size' => '6', 'stock' => 0],
    ]);

    Livewire::test(ProductPage::class, ['slug' => 'test-brand-sold-out'])
        ->assertSet('colour', '')
        ->call('addToCart')
        ->assertSee('Please select a colour');
});

it('shows a single-value option as text instead of a picker', function (): void {
    $html = $this->get(route('product', 'amblers-safety-fs9'))->assertOk()->getContent();

    expect($html)->toMatch('#<p data-option="colour" data-fixed>.*?Black#s')
        ->and($html)->not->toContain('<fieldset data-option="colour"')
        ->and($html)->toContain('data-option="shoe_size"');
});

it('adds a product with one variant and no options directly', function (): void {
    CatalogueFactory::product('Plain', [['stock' => 4]]);

    Livewire::test(ProductPage::class, ['slug' => 'test-brand-plain'])
        ->set('quantity', '1')
        ->call('addToCart')
        ->assertSet('missingOption', null)
        ->assertSet('added', true);
});

it('disables sizes without stock but still shows them', function (): void {
    $html = $this->get(route('product', 'amblers-safety-fs9'))->getContent();

    // FS9: 5, 6 and 7 are in stock; 4, 10, 11 and 12 are not.
    expect(enabledSizes($html))->toBe(['5', '6', '7']);

    foreach (['4', '10', '11', '12'] as $size) {
        expect($html)->toContain('data-value="'.$size.'"');
    }
});

it('re-evaluates colours after a size is chosen', function (): void {
    $component = Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero'])
        ->call('choose', 'shoe_size', '10');

    // Black 10 is out of stock, so Black is now disabled.
    expect($component->html())->toMatch('#<button[^>]*data-value="black"[^>]*\sdisabled#s');
});

it('shows a stock message for the selection', function (string $slug, ?string $size, string $message): void {
    $component = Livewire::test(ProductPage::class, ['slug' => $slug]);

    if ($size !== null) {
        $component->call('choose', 'shoe_size', $size);
    }

    $component->assertSee($message);
})->with([
    ['safety-jogger-ligero', null, 'In stock'],
    ['safety-jogger-ligero', '8', 'In stock'],
    ['amblers-safety-fs65', '6', 'Only 3 left'],
    ['amblers-safety-fs65', '5', 'Only 1 left'],
    ['maxguard-sx310', null, 'Out of stock'],
    ['maxguard-sx310', '8', 'Out of stock'],
]);

it('shows a standard rated product ex VAT, without its RRP', function (): void {
    $this->get(route('product', 'safety-jogger-ligero'))
        ->assertSee('£68.90')
        ->assertDontSee('£76.50')
        ->assertSee('ex VAT')
        ->assertDontSee('£82.68')
        ->assertDontSee('£91.80')
        ->assertDontSee('inc VAT');
});

it('shows a zero-protective product ex VAT, with no VAT note under the price', function (): void {
    $this->get(route('product', 'amblers-safety-fs9'))
        ->assertSee('£52.48')
        ->assertSee('ex VAT')
        ->assertDontSee('Zero rated for VAT')
        ->assertDontSee('VAT at 20% applies when buying for employees');
});

it('shows no employer VAT note on a standard rated product', function (): void {
    $this->get(route('product', 'safety-jogger-ligero'))->assertDontSee('buying for employees');
});

it('outputs Product structured data with an offer per variant', function (): void {
    $data = structuredData($this->get(route('product', 'safety-jogger-ligero'))->getContent());
    $offers = collect($data['offers'])->keyBy('sku');

    expect($data['@context'])->toBe('https://schema.org')
        ->and($data['@type'])->toBe('Product')
        ->and($data['name'])->toBe('Safety Jogger LIGERO')
        ->and($data['brand'])->toBe(['@type' => 'Brand', 'name' => 'Safety Jogger'])
        ->and($data['url'])->toBe(route('product', 'safety-jogger-ligero'))
        ->and($data['image'])->toHaveCount(3)
        ->and($offers)->toHaveCount(36)
        ->and($offers['SJ-1492552'])->toMatchArray([
            '@type' => 'Offer',
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification',
                'price' => '68.90',
                'priceCurrency' => 'GBP',
                'valueAddedTaxIncluded' => false,
            ],
            'availability' => 'https://schema.org/InStock',
            'gtin13' => '5400812979016',
            'url' => route('product', 'safety-jogger-ligero').'?colour=navy',
        ])
        ->and($offers['SJ-1492635']['availability'])->toBe('https://schema.org/OutOfStock');
});

it('states every offer price ex VAT, explicitly, in the structured data', function (): void {
    $ligero = structuredData($this->get(route('product', 'safety-jogger-ligero'))->getContent());
    $fs9 = structuredData($this->get(route('product', 'amblers-safety-fs9'))->getContent());

    foreach ([$ligero, $fs9] as $data) {
        foreach ($data['offers'] as $offer) {
            // Only the specification carries a price, and it says VAT is not in it.
            expect($offer)->not->toHaveKey('price')
                ->and($offer['priceSpecification']['@type'])->toBe('UnitPriceSpecification')
                ->and($offer['priceSpecification']['valueAddedTaxIncluded'])->toBeFalse()
                ->and($offer['priceSpecification']['priceCurrency'])->toBe('GBP');
        }
    }

    // LIGERO is 68.90 ex VAT (82.68 inc VAT); the zero-protective FS9 is 52.48 either way.
    expect($ligero['offers'][0]['priceSpecification']['price'])->toBe('68.90')
        ->and($fs9['offers'][0]['priceSpecification']['price'])->toBe('52.48');
});

it('has a canonical URL without the colour', function (): void {
    $this->get(route('product', 'safety-jogger-ligero').'?colour=black')
        ->assertSee('<link rel="canonical" href="'.route('product', 'safety-jogger-ligero').'">', false);
});

it('lists published cross-sell products only', function (): void {
    app(FixtureCatalogueSeeder::class)->run(base_path('tests/Fixtures/related-products.json'));

    $html = $this->get(route('product', 'safety-jogger-duplicate'))->assertOk()->getContent();

    expect($html)->toContain('Related products')
        ->and($html)->toContain(route('product', 'safety-jogger-duplicate-2'));

    Url::query()->where('slug', 'safety-jogger-duplicate-2')->sole()->element->update(['status' => 'draft']);

    $this->get(route('product', 'safety-jogger-duplicate'))->assertOk()->assertDontSee(route('product', 'safety-jogger-duplicate-2'));
});

/** The URL of the published product with this name. */
function relatedTestUrl(string $name): string
{
    $product = Product::query()->where('status', 'published')->get()
        ->first(fn (Product $product): bool => $product->translateAttribute('name') === $name);

    expect($product)->not->toBeNull();

    return route('product', $product->defaultUrl->slug);
}

/** The related products section of a page, or an empty string. */
function relatedSection(string $html): string
{
    preg_match('#<section[^>]*data-testid="related".*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

it('fills the related products with in-stock products from the same category when there are no vendor links', function (): void {
    SearchIndex::use();

    $section = relatedSection($this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent());

    // Safety Shoes holds Ligero, SX310 (nothing in stock) and FS65.
    expect($section)->toContain('Related products')
        ->and($section)->toContain(relatedTestUrl('FS65'))
        ->and($section)->not->toContain(relatedTestUrl('SX310'))
        ->and($section)->not->toContain(route('product', 'safety-jogger-ligero'))
        ->and($section)->not->toContain(relatedTestUrl('Classic'));
});

it('shows the related products on the same card as the listings', function (): void {
    SearchIndex::use();

    $related = relatedSection($this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent());
    $listing = $this->get(route('category', 'safety-footwear/safety-shoes'))->assertOk()->getContent();

    // FS65 comes in several colours, so its card has swatches, a price and the View product button, like in the listing.
    expect($related)->toContain('data-swatches')
        ->and($related)->toContain('data-price')
        ->and($related)->toContain('data-view-product')
        ->and($related)->toContain('group relative flex flex-col rounded border border-border p-3')
        ->and($listing)->toContain('group relative flex flex-col rounded border border-border p-3')
        ->and($listing)->toContain('data-view-product');
});

it('shows the brand logo above the product name, linking to the brand page', function (): void {
    $this->seed(StorefrontBrandSeeder::class);

    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<a[^>]*data-product-brand="safety-jogger"[^>]*>.*?</a>#s', $html, $brand);

    expect($brand[0] ?? '')->toContain('href="'.route('brands.show', 'safety-jogger').'"')
        ->toContain('data-product-brand-logo')
        ->toContain(asset('images/brands/trimmed/safety-jogger.png'))
        ->toContain('w-[100px]')
        ->toContain('alt="Safety Jogger"')
        ->and(strpos($html, 'data-product-brand-logo'))->toBeLessThan(strpos($html, 'id="product-heading"'));
});

it('shows the brand name instead when it has no logo', function (): void {
    // Test Brand has no storefront brand record, so no logo.
    CatalogueFactory::product('Plain', [['stock' => 1]]);

    $html = $this->get(route('product', 'test-brand-plain'))->assertOk()->getContent();

    preg_match('#<a[^>]*data-product-brand=[^>]*>.*?</a>#s', $html, $brand);

    expect($brand[0] ?? '')->toContain('data-product-brand="test-brand"')->toContain('Test Brand')->not->toContain('<img');
});

it('gives the product name some room above and below', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    expect($html)->toMatch('#<h1 id="product-heading" class="[^"]*leading-tight[^"]*">#')
        ->and($html)->toMatch('#<p class="mt-2 text-lg text-ink-muted">#');
});

it('shows the safety standard as the vendor does: the class and codes on one line, the standards under it', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-safety-standard.*?</section>#s', $html, $panel);
    $panel = $panel[0] ?? '';

    expect($panel)->toContain('Safety standard')
        ->and($panel)->toMatch('#data-safety-class>S1P<#')
        ->and($panel)->toMatch('#data-safety-code[^>]*>SRC<#')
        ->and($panel)->toMatch('#data-safety-code[^>]*>CI<#')
        ->and($panel)->toMatch('#data-safety-code[^>]*>ESD<#')
        // METAL_FREE is a phrase, not a code, so it is not in the short line.
        ->and($panel)->not->toContain('>METAL_FREE<')
        ->and($panel)->toMatch('#data-standards[^>]*>ASTM F2413:2024 - EN ISO 20345:2011<#')
        // What a code means is its hover text, not more lines in the block.
        ->and($panel)->toContain('title="Cold insulated (CI): The sole insulates the foot against cold ground."')
        ->and($panel)->not->toContain('<dl')
        ->and($panel)->not->toContain('border');

    // The class comes before the codes, and the codes before the standards.
    expect(strpos($panel, 'data-safety-class'))->toBeLessThan(strpos($panel, 'data-safety-code'))
        ->and(strpos($panel, 'data-safety-code'))->toBeLessThan(strpos($panel, 'data-standards'));

    // They are no longer rows of the specification table as well.
    preg_match('#<table[^>]*data-testid="specification".*?</table>#s', $html, $table);

    expect($table[0] ?? '')->not->toContain('Safety class')->not->toContain('>Standards<')->not->toContain('>Features<');
});

it('shows fastening and the country of origin by name in the specification', function (): void {
    $product = Url::query()->where('slug', 'safety-jogger-ligero')->sole()->element;
    $data = $product->attribute_data;
    $data['fastening'] = new Text('Laces');
    $product->update(['attribute_data' => $data]);

    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<table[^>]*data-testid="specification".*?</table>#s', $html, $table);

    expect($table[0] ?? '')->toContain('Fastening')->toContain('Laces')->toContain('Country of origin')->toContain('China')->not->toContain('>CN<');
});

it('lists the industries as links to the listing filtered to each', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-industries.*?</section>#s', $html, $section);
    $section = $section[0] ?? '';

    expect($section)->toContain('Industries')
        ->and($section)->toContain('href="'.route('search', ['industry' => ['Automotive']]).'"')
        ->and($section)->toContain('data-industry="Logistics"')
        ->and($section)->toContain('data-industry="Industry"');
});

it('leaves out the safety standard and industries when the product has none', function (): void {
    CatalogueFactory::product('Plain', [['stock' => 1]]);

    $html = $this->get(route('product', 'test-brand-plain'))->assertOk()->getContent();

    expect($html)->not->toContain('data-safety-standard')
        ->and($html)->not->toContain('data-industries');
});

it('shows the vendor pictograms that have an icon file, with their names', function (): void {
    $directory = public_path('images/pictograms');
    $icon = $directory.'/zz-test-icon.png';

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents($icon, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    try {
        $product = Url::query()->where('slug', 'safety-jogger-ligero')->sole()->element;
        $data = $product->attribute_data;
        $data['pictograms'] = new ListField(['zz-test-icon|Test icon', 'no-such-file|Missing icon', 'bad name|Bad']);
        $product->update(['attribute_data' => $data]);

        $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

        preg_match('#<section[^>]*data-pictograms.*?</section>#s', $html, $section);

        expect($section[0] ?? '')->toContain('data-pictogram="zz-test-icon"')
            ->toContain(asset('images/pictograms/zz-test-icon.png'))
            ->toContain('Test icon')
            ->not->toContain('Missing icon')
            ->not->toContain('Bad');
    } finally {
        unlink($icon);
    }
});

/** Sets the `pictograms` attribute of the Ligero product to these `name|Label` entries. */
function setLigeroPictograms(array $entries): void
{
    $product = Url::query()->where('slug', 'safety-jogger-ligero')->sole()->element;
    $data = $product->attribute_data;
    $data['pictograms'] = new ListField($entries);
    $product->update(['attribute_data' => $data]);
}

it('shows only the pictograms the vendor lists, in its order, and not as links', function (): void {
    setLigeroPictograms(['coldinsulated|Cold insulated (CI)', 'ce|CE', 'metalfree|Metal free']);

    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-pictograms.*?</section>#s', $html, $section);
    $section = $section[0] ?? '';

    preg_match_all('#data-pictogram="([^"]+)"#', $section, $names);

    // Exactly the list, in its order: nothing is added from the product's features (Ligero has SRC and a toecap).
    expect($names[1])->toBe(['coldinsulated', 'ce', 'metalfree'])
        ->and($section)->toContain(asset('images/pictograms/coldinsulated.png'))
        ->and($section)->toContain('Cold insulated (CI)')
        ->and($section)->not->toContain('<a ')
        ->and($section)->not->toContain('data-pictogram="slipresistantoutsole"');
});

it('leaves out a listed pictogram that has no icon file, and a malformed entry', function (): void {
    setLigeroPictograms(['ce|CE', 'leather-not-downloaded|Leather', 'bad name|Bad', 'metalfree']);

    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match_all('#data-pictogram="([^"]+)"#', $html, $names);

    expect($names[1])->toBe(['ce'])->and($html)->not->toContain('Leather');
});

it('shows no pictograms block when the vendor lists none, however many features the product has', function (): void {
    $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->assertDontSee('data-pictograms', false);
});

it('has the icon files of the vendor set committed', function (): void {
    foreach (['ce', 'safetytoecap', 'scuffcap', 'punctureresistant', 'slipresistantoutsole', 'heelenergyabsorption', 'oilandfuelresistantoutsole', 'metalfree', 'lightweight', 'waterpenetrationandabsorption', 'electrostaticdischarge35', 'coldinsulated', '3fitsystem', 'individualorthopedicsolutions'] as $name) {
        expect(is_file(public_path("images/pictograms/{$name}.png")))->toBeTrue("{$name}.png is missing");
    }
});

it('offers the English product sheet for the chosen colour, and no other document', function (): void {
    $product = Url::query()->where('slug', 'safety-jogger-ligero')->sole()->element;
    $directory = storage_path('app/private/fixture-placeholders');

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    foreach ([['Navy', 'product_sheet', 'LIGERO_NAV_en'], ['Black', 'product_sheet', 'LIGERO_BLK_en'], ['Navy', 'certificate', 'LIGERO_CERT']] as [$colour, $type, $name]) {
        $file = $directory.'/'.$name.'.pdf';
        file_put_contents($file, "%PDF-1.4\n%%EOF\n");

        $product->addMedia($file)
            ->withCustomProperties(['type' => $type, 'colour' => $colour, 'language' => 'en'])
            ->toMediaCollection('documents');
    }

    $navy = $this->get(route('product', 'safety-jogger-ligero').'?colour=navy')->assertOk()->getContent();
    $black = $this->get(route('product', 'safety-jogger-ligero').'?colour=black')->assertOk()->getContent();

    preg_match('#<p[^>]*data-product-sheet.*?</p>#s', $navy, $navyLink);
    preg_match('#<p[^>]*data-product-sheet.*?</p>#s', $black, $blackLink);

    expect($navyLink[0] ?? '')->toContain('LIGERO_NAV_en')->toContain('Product sheet, Navy (PDF)')->not->toContain('LIGERO_BLK_en')
        ->and($blackLink[0] ?? '')->toContain('LIGERO_BLK_en')->toContain('Product sheet, Black (PDF)')
        // A certificate is a download too, and downloads other than the product sheet are not shown.
        ->and($navy)->not->toContain('LIGERO_CERT');
});

it('shows no product sheet link when the product has none', function (): void {
    $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->assertDontSee('data-product-sheet', false);
});

it('always puts the main gallery image on a white background', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<div class="([^"]*)" data-gallery-frame>#', $html, $frame);

    expect($frame[1] ?? '')->toContain('bg-white')->not->toContain('bg-surface-muted');
});

it('keeps 10px of space round the main gallery image', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<img[^>]*data-gallery-main[^>]*>#', $html, $image);

    expect($image[0] ?? '')->toContain('object-contain')->toContain('p-2.5');
});

it('only suggests products from the same category', function (): void {
    SearchIndex::use();

    $section = relatedSection($this->get(route('product', 'gbs-eva'))->assertOk()->getContent());

    expect($section)->toContain(relatedTestUrl('Exeter Slipper'))
        ->and($section)->not->toContain(relatedTestUrl('LIGERO'))
        ->and($section)->not->toContain(route('product', 'gbs-eva'));
});

it('puts the vendor links first and tops the list up from the category', function (): void {
    SearchIndex::use();
    app(FixtureCatalogueSeeder::class)->run(base_path('tests/Fixtures/related-products.json'));

    // The seeder indexes what it creates, and the test index outlives the database rollback while other tests count what is in
    // it: the two products are taken out of the index again whatever happens.
    $ids = Url::query()->where('slug', 'like', 'safety-jogger-duplicate%')->pluck('element_id')->all();

    try {
        $section = relatedSection($this->get(route('product', 'safety-jogger-duplicate'))->assertOk()->getContent());

        expect($section)->toContain(route('product', 'safety-jogger-duplicate-2'))
            ->and(strpos($section, route('product', 'safety-jogger-duplicate-2')))->toBeLessThan(strpos($section, relatedTestUrl('FS65')))
            ->and(substr_count($section, 'data-related-product='))->toBeLessThanOrEqual(4);
    } finally {
        app(Client::class)->index((new Product)->searchableAs())->deleteDocuments($ids);
        SearchIndex::settle();
    }
});

it('chooses the related products once, so picking a colour does not search again', function (): void {
    SearchIndex::use();

    $component = Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero']);
    $ids = $component->get('relatedIds');

    expect($ids)->not->toBe([]);

    // From here on the index would fail, and the section still renders from the ids chosen on mount.
    $this->app->instance(Client::class, new Client('http://127.0.0.1:1'));

    $component->call('choose', 'colour', 'black')->assertSee('Related products')->assertSet('relatedIds', $ids);
});

it('still shows the page, without the section, when the search index cannot be reached', function (): void {
    $this->app->instance(Client::class, new Client('http://127.0.0.1:1'));

    $this->get(route('product', 'gbs-eva'))->assertOk()->assertDontSee('Related products');
});

it('does not embed a video from an unexpected host', function (): void {
    $product = Product::query()->whereHas('urls', fn ($query) => $query->where('slug', 'gbs-eva'))->sole();
    $data = $product->attribute_data;
    $data['video_url'] = new Text('https://evil.example/embed/abc');
    $product->update(['attribute_data' => $data]);

    $this->get(route('product', 'gbs-eva'))->assertOk()->assertDontSee('<iframe', false);
});

it('lets the customer choose a thumbnail to show in the main frame', function (): void {
    $product = Url::query()->where('slug', 'safety-jogger-ligero')->sole()->element;
    $directory = storage_path('app/private/fixture-placeholders');

    foreach (['detail', 'outsole'] as $role) {
        $product
            ->addMedia(app(PlaceholderImageGenerator::class)->generate("Ligero Navy {$role}", '394656', $directory, 240))
            ->withCustomProperties(['colour' => 'Navy', 'role' => $role, 'primary' => false])
            ->toMediaCollection(config('lunar.media.collection'));
    }

    $html = $this->get(route('product', 'safety-jogger-ligero').'?colour=navy')->assertOk()->getContent();

    preg_match('#<section[^>]*data-testid="gallery".*?</section>#s', $html, $gallery);
    preg_match_all('#<button[^>]*data-gallery-thumb="(\d)"[^>]*>#s', $gallery[0], $thumbs);

    // One button per image, each selecting its own index.
    expect($thumbs[1])->toBe(['0', '1', '2'])
        ->and($thumbs[0][1])->toContain('x-on:click="active = 1"')
        ->and($thumbs[0][2])->toContain('aria-label="Show image 3 of 3: outsole view"')
        // The first image is the one shown, with or without JavaScript.
        ->and($thumbs[0][0])->toContain('aria-current="true"')
        ->and($gallery[0])->toContain('x-bind:src="images[active]"')
        ->and(substr_count($gallery[0], '/conversions/'))->toBeGreaterThanOrEqual(4);

    // The main frame can show every image of the colour, and only those.
    // The medium rendition of all three is in the gallery's data, plus the
    // one in the main frame's own src.
    preg_match('#x-data="([^"]*)"#', $gallery[0], $data);

    expect($data[1])->toContain('active: 0')
        ->and(substr_count(html_entity_decode($data[1], ENT_QUOTES), '-medium.'))->toBe(3);

    // Black has a single image: no thumbnails, and a different gallery key,
    // so choosing a colour starts on that colour's first image.
    $black = $this->get(route('product', 'safety-jogger-ligero').'?colour=black')->getContent();
    preg_match('#<section[^>]*data-testid="gallery"[^>]*>#s', $black, $blackGallery);
    preg_match('#<section[^>]*data-testid="gallery"[^>]*>#s', $html, $navyGallery);
    preg_match('#wire:key="(gallery-\d+)"#', $blackGallery[0], $blackKey);
    preg_match('#wire:key="(gallery-\d+)"#', $navyGallery[0], $navyKey);

    expect($black)->not->toContain('data-gallery-thumb')
        ->and($blackKey[1])->not->toBe($navyKey[1]);
});

/**
 * The add to cart button: whether it is disabled, and what it says.
 *
 * @return array{disabled: bool, label: string}
 */
function addButton(string $html): array
{
    preg_match('#<button[^>]*data-add-to-cart[^>]*>(.*?)</button>#s', $html, $button);

    return [
        // The attribute, not the `disabled:` utility classes.
        'disabled' => preg_match('#\sdisabled(?![:\w-])#', explode('>', $button[0])[0]) === 1,
        'label' => trim($button[1]),
    ];
}

it('keeps add to cart disabled until a size and a quantity are chosen, and says what is needed', function (): void {
    $component = Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero']);

    // The colour is pre-selected. The size and the quantity never are.
    expect(addButton($component->html()))->toBe(['disabled' => true, 'label' => 'Select a size'])
        ->and($component->get('quantity'))->toBe('');

    $component->call('choose', 'shoe_size', '8');

    expect(addButton($component->html()))->toBe(['disabled' => true, 'label' => 'Enter a quantity']);

    $component->set('quantity', '3');

    expect(addButton($component->html()))->toBe(['disabled' => false, 'label' => 'Add to cart']);

    // Clearing the quantity disables it again.
    $component->set('quantity', '');

    expect(addButton($component->html()))->toBe(['disabled' => true, 'label' => 'Enter a quantity']);

    // Choosing a combination that is out of stock disables it whatever the quantity.
    $component->set('quantity', '1')->call('choose', 'colour', 'black')->call('choose', 'shoe_size', '10');

    expect(addButton($component->html()))->toBe(['disabled' => true, 'label' => 'Out of stock']);
});

it('entering a quantity first still needs a size', function (): void {
    $component = Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero'])->set('quantity', '2');

    expect(addButton($component->html()))->toBe(['disabled' => true, 'label' => 'Select a size']);

    $component->call('addToCart')->assertSet('added', false)->assertSet('missingOption', 'shoe_size');
});

it('rejects a quantity that is not a whole number of 1 or more', function (string $quantity): void {
    $component = Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero'])
        ->call('choose', 'shoe_size', '8')
        ->set('quantity', $quantity);

    expect(addButton($component->html())['disabled'])->toBeTrue();

    $component
        ->assertSee('Enter a whole number, 1 or more.')
        ->assertSeeHtml('aria-invalid="true"')
        ->call('addToCart')
        ->assertSet('added', false);
})->with(['0', '-2', '1.5', 'abc', '9999999']);

it('does not allow more than the stock of the chosen size', function (): void {
    // FS65 in size 6 has 3 in stock.
    $component = Livewire::test(ProductPage::class, ['slug' => 'amblers-safety-fs65'])
        ->call('choose', 'shoe_size', '6')
        ->set('quantity', '4');

    expect(addButton($component->html())['disabled'])->toBeTrue();

    $component
        ->assertSee('Only 3 available in this size.')
        ->assertSeeHtml('max="3"')
        ->call('addToCart')
        ->assertSet('added', false)
        ->set('quantity', '3');

    expect(addButton($component->html()))->toBe(['disabled' => false, 'label' => 'Add to cart']);

    $component->assertDontSee('available in this size')->call('addToCart')->assertSet('added', true);
});

it('shows the stock of the chosen variant next to the quantity field once every option is chosen, and uses it as the max', function (): void {
    $component = Livewire::test(ProductPage::class, ['slug' => 'amblers-safety-fs65']);

    // No size yet: no stock figure, and no max.
    expect($component->html())->not->toContain('data-stock-available')
        ->and($component->html())->not->toMatch('/<input[^>]*data-quantity[^>]*max=/s');

    $component->call('choose', 'shoe_size', '6'); // 3 in stock

    preg_match('#(<p[^>]*data-stock-available[^>]*>)(.*?)</p>#s', $component->html(), $stock);

    // A badge (the dot before the text is decoration only), red because 3 is low stock.
    expect(trim(strip_tags($stock[2] ?? '')))->toBe('3 available')
        ->and($stock[1])->toContain('rounded-full')
        ->and($stock[1])->toContain('border-danger')
        ->and($stock[1])->not->toContain('border-success');
    $component->assertSeeHtml('max="3"');

    // The figure follows the selection.
    $component->call('choose', 'shoe_size', '5'); // 1 in stock

    preg_match('#<p[^>]*data-stock-available[^>]*>(.*?)</p>#s', $component->html(), $stock);

    expect(trim(strip_tags($stock[1] ?? '')))->toBe('1 available');
    $component->assertSeeHtml('max="1"');
});

it('shows a green stock badge when there is plenty in stock', function (): void {
    // LIGERO in size 8 is "In stock", above the low-stock line.
    $component = Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero'])->call('choose', 'shoe_size', '8');

    preg_match('#(<p[^>]*data-stock-available[^>]*>)(.*?)</p>#s', $component->html(), $stock);

    expect($stock[1])->toContain('rounded-full')
        ->and($stock[1])->toContain('border-success')
        ->and($stock[1])->not->toContain('border-danger')
        ->and(trim(strip_tags($stock[2])))->toMatch('/^\d+ available$/');
});

it('says out of stock next to the quantity field for a variant with no stock', function (): void {
    $component = Livewire::test(ProductPage::class, ['slug' => 'safety-jogger-ligero'])
        ->call('choose', 'colour', 'black')
        ->call('choose', 'shoe_size', '10');

    preg_match('#<p[^>]*data-stock-available[^>]*>(.*?)</p>#s', $component->html(), $stock);

    expect(trim(strip_tags($stock[1] ?? '')))->toBe('Out of stock')
        ->and($component->html())->not->toMatch('/<input[^>]*data-quantity[^>]*max=/s');
});

it('shows a labelled quantity field that starts empty', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<input[^>]*data-quantity[^>]*>#s', $html, $input);

    expect($html)->toContain('<label for="quantity"')
        ->and($input[0])->toContain('type="number"')
        ->and($input[0])->toContain('min="1"')
        ->and($input[0])->not->toContain('value=')
        ->and($input[0])->not->toContain('aria-invalid');
});

it('says out of stock, not select a size, when nothing is in stock', function (): void {
    expect(addButton($this->get(route('product', 'maxguard-sx310'))->getContent()))
        ->toBe(['disabled' => true, 'label' => 'Out of stock']);
});

it('links to the size guide next to the size selector', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    preg_match('#<fieldset[^>]*data-option="shoe_size".*?</fieldset>#s', $html, $fieldset);

    expect($fieldset[0] ?? '')->toContain('href="'.route('size-guide').'"')
        ->and($fieldset[0])->toContain('data-size-guide-link');
});
