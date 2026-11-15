<?php

declare(strict_types=1);

use App\Domain\Catalogue\Media\PlaceholderImageGenerator;
use App\Livewire\Catalogue\ProductPage;
use Database\Seeders\FixtureCatalogueSeeder;
use Livewire\Livewire;
use Lunar\FieldTypes\Text;
use Lunar\Models\Product;
use Lunar\Models\Url;
use Tests\Support\CatalogueFactory;

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

it('shows the product with its price, description and specification', function (): void {
    $this->get('/p/safety-jogger-ligero')
        ->assertOk()
        ->assertSee('LIGERO')
        ->assertSee('Lightweight ESD Safety Shoes')
        ->assertSee('Ultra-light, flexible safety shoe')
        ->assertSee('£68.90')
        ->assertSee('£76.50')
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
    $this->get('/p/does-not-exist')->assertNotFound();

    CatalogueFactory::product('Hidden', [['stock' => 1]], status: 'draft');

    $this->get('/p/test-brand-hidden')->assertNotFound();
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
    $html = $this->get('/p/safety-jogger-ligero?colour=black')->assertOk()->getContent();

    expect(galleryColours($html))->toBe(['Black'])
        ->and(enabledSizes($html))->toBe(['3.5', '4', '5', '6', '6.5', '7', '8', '9', '10.5', '11', '12'])
        ->and($html)->toContain('data-value="10"');

    $navy = $this->get('/p/safety-jogger-ligero?colour=navy')->getContent();

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
    $html = $this->get('/p/amblers-safety-fs9')->assertOk()->getContent();

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
    $html = $this->get('/p/amblers-safety-fs9')->getContent();

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

it('shows a standard rated product ex VAT, with its compare price ex VAT', function (): void {
    $this->get('/p/safety-jogger-ligero')
        ->assertSee('£68.90')
        ->assertSee('£76.50')
        ->assertSee('ex VAT')
        ->assertDontSee('£82.68')
        ->assertDontSee('£91.80')
        ->assertDontSee('inc VAT');
});

it('shows a zero-protective product ex VAT, with a note about employers', function (): void {
    $this->get('/p/amblers-safety-fs9')
        ->assertSee('£52.48')
        ->assertSee('ex VAT')
        ->assertSee('VAT at 20% applies when buying for employees');
});

it('shows no employer VAT note on a standard rated product', function (): void {
    $this->get('/p/safety-jogger-ligero')->assertDontSee('buying for employees');
});

it('outputs Product structured data with an offer per variant', function (): void {
    $data = structuredData($this->get('/p/safety-jogger-ligero')->getContent());
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
            'price' => '82.68',
            'priceCurrency' => 'GBP',
            'availability' => 'https://schema.org/InStock',
            'gtin13' => '5400812979016',
            'url' => route('product', 'safety-jogger-ligero').'?colour=navy',
        ])
        ->and($offers['SJ-1492635']['availability'])->toBe('https://schema.org/OutOfStock');
});

it('keeps structured data prices inc VAT although the page shows ex VAT', function (): void {
    $ligero = structuredData($this->get('/p/safety-jogger-ligero')->getContent());
    $fs9 = structuredData($this->get('/p/amblers-safety-fs9')->getContent());

    expect($ligero['offers'][0]['price'])->toBe('82.68')
        ->and($fs9['offers'][0]['price'])->toBe('52.48');
});

it('has a canonical URL without the colour', function (): void {
    $this->get('/p/safety-jogger-ligero?colour=black')
        ->assertSee('<link rel="canonical" href="'.route('product', 'safety-jogger-ligero').'">', false);
});

it('lists published cross-sell products only', function (): void {
    app(FixtureCatalogueSeeder::class)->run(base_path('tests/Fixtures/related-products.json'));

    $html = $this->get('/p/safety-jogger-duplicate')->assertOk()->getContent();

    expect($html)->toContain('Related products')
        ->and($html)->toContain(route('product', 'safety-jogger-duplicate-2'));

    Url::query()->where('slug', 'safety-jogger-duplicate-2')->sole()->element->update(['status' => 'draft']);

    $this->get('/p/safety-jogger-duplicate')->assertOk()->assertDontSee('Related products');
});

it('shows no related section when there are no associations', function (): void {
    $this->get('/p/gbs-eva')->assertOk()->assertDontSee('Related products');
});

it('does not embed a video from an unexpected host', function (): void {
    $product = Product::query()->whereHas('urls', fn ($query) => $query->where('slug', 'gbs-eva'))->sole();
    $data = $product->attribute_data;
    $data['video_url'] = new Text('https://evil.example/embed/abc');
    $product->update(['attribute_data' => $data]);

    $this->get('/p/gbs-eva')->assertOk()->assertDontSee('<iframe', false);
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

    $html = $this->get('/p/safety-jogger-ligero?colour=navy')->assertOk()->getContent();

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
    $black = $this->get('/p/safety-jogger-ligero?colour=black')->getContent();
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

it('shows a labelled quantity field that starts empty', function (): void {
    $html = $this->get('/p/safety-jogger-ligero')->assertOk()->getContent();

    preg_match('#<input[^>]*data-quantity[^>]*>#s', $html, $input);

    expect($html)->toContain('<label for="quantity"')
        ->and($input[0])->toContain('type="number"')
        ->and($input[0])->toContain('min="1"')
        ->and($input[0])->not->toContain('value=')
        ->and($input[0])->not->toContain('aria-invalid');
});

it('says out of stock, not select a size, when nothing is in stock', function (): void {
    expect(addButton($this->get('/p/maxguard-sx310')->getContent()))
        ->toBe(['disabled' => true, 'label' => 'Out of stock']);
});
