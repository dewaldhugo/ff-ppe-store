<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use App\Domain\VendorSync\Images\ProductImageSync;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Import\StockSync;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Models\VendorRawRecord;
use Database\Seeders\FixtureCatalogueSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
});

/**
 * The recorded list and details, plus minimal details for the four list
 * items that have no recorded detail.
 *
 * @return array{0: list<array<string, mixed>>, 1: array<int, array<string, mixed>>}
 */
function sjCatalogue(): array
{
    return [
        VendorSync::json('SafetyJogger/product-list.json'),
        [
            284096 => VendorSync::json('SafetyJogger/product-284096.json'),
            279074 => VendorSync::json('SafetyJogger/product-279074.json'),
            215754 => VendorSync::json('SafetyJogger/product-215754.json'),
            // Shoes with EN ISO 20345 and no RRP: imported uncapped.
            407407 => VendorSync::detail(407407, '2081', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022'], cost: 290.6, rrp: 0.0),
            // Workwear: not a `Shoes` article type.
            388610 => VendorSync::detail(388610, 'ALKSHORT2', 'Safety Workwear', 'Workwear', ['EN ISO 13688']),
            // Adventure: shoes without an EN ISO 20345 or 20347 norm.
            360862 => VendorSync::detail(360862, 'BOGOTA', 'Safety Jogger Adventure', 'Shoes', []),
            // Oxypas: shoes with EN ISO 20347.
            296233 => VendorSync::detail(296233, 'ALIZA', 'Oxypas', 'Shoes', ['EN ISO 20347:2012'], cost: 9.9, rrp: 55.1),
        ],
    ];
}

function sjVariant(string $sku): ProductVariant
{
    return ProductVariant::query()->where('sku', $sku)->sole();
}

function sjTax(Product $product): array
{
    return $product->variants->map(fn (ProductVariant $variant): ?string => CatalogueKeys::handleFor(KeyType::TaxClass, $variant->taxClass))->unique()->values()->all();
}

/**
 * Everything the data contract says about a product, in a comparable form.
 *
 * @return array<string, mixed>
 */
function contractShape(Product $product): array
{
    $product->load(['brand', 'productType', 'collections', 'productOptions', 'variants.values.option', 'variants.prices', 'variants.taxClass']);

    $attributes = [];

    foreach (['name', 'title', 'short_description', 'description', 'key_points', 'safety_class', 'features', 'standards', 'toecap', 'midsole', 'outsole', 'upper', 'lining', 'cut', 'fastening', 'industries', 'gender', 'country_of_origin', 'video_url', 'certificates'] as $handle) {
        $attributes[$handle] = $product->translateAttribute($handle);
    }

    $variants = [];

    foreach ($product->variants as $variant) {
        $colour = $variant->values->firstWhere('option.handle', 'colour');
        $size = $variant->values->firstWhere('option.handle', 'shoe_size');
        $price = $variant->prices->sole();
        $offer = VendorOffer::query()->where('product_variant_id', $variant->id)->sole();

        $variants[$variant->sku] = [
            'ean' => $variant->ean,
            'stock' => $variant->stock,
            'purchasable' => $variant->purchasable,
            'shippable' => (bool) $variant->shippable,
            'backorder' => (int) $variant->backorder,
            'unit_quantity' => (int) $variant->unit_quantity,
            'tax_class' => CatalogueKeys::handleFor(KeyType::TaxClass, $variant->taxClass),
            'colour' => $colour->translate('name'),
            'hex' => $colour->meta['hex'] ?? null,
            'shoe_size' => $size->translate('name'),
            'sort' => (float) $size->meta['sort'],
            'eu_size' => $variant->attr('eu_size'),
            'weight' => [(float) $variant->weight_value, $variant->weight_unit],
            'dimensions' => [$variant->length_value, $variant->width_value, $variant->height_value],
            'price' => $price->getRawOriginal('price'),
            'compare_price' => $price->getRawOriginal('compare_price'),
            'customer_group' => $price->customer_group_id,
            'currency' => $price->currency_id,
            'min_quantity' => $price->min_quantity,
            'offer' => $offer->only(['vendor_sku', 'vendor_product_name', 'vendor_colour', 'vendor_size', 'ean', 'cost_price', 'stock']),
        ];
    }

    ksort($variants);

    $link = VendorProduct::query()->where('product_id', $product->id)->sole();

    return [
        'product_type' => CatalogueKeys::handleFor(KeyType::ProductType, $product->productType),
        'status' => $product->status,
        'brand' => $product->brand?->name,
        'attributes' => $attributes,
        'collections' => $product->collections->map(fn ($collection): ?string => CatalogueKeys::handleFor(KeyType::Collection, $collection))->sort()->values()->all(),
        'options' => $product->productOptions->pluck('handle')->all(),
        'vendor_product' => $link->only(['vendor_product_id', 'commodity_code']),
        'variants' => $variants,
    ];
}

it('imports the Ligero fixture in the same shape as the fixture seeder', function (): void {
    // The fixture seeder's Ligero, as the reference.
    $this->seed(FixtureCatalogueSeeder::class);
    $expected = contractShape(VendorSync::product('SJ', '284096'));

    VendorSync::clearCatalogue();

    $ligero = VendorSync::json('SafetyJogger/product-284096.json');
    VendorSync::fakeHttp([['id' => 284096, 'brandDescription' => 'Safety Jogger']], [284096 => $ligero]);

    $report = VendorSync::import('SJ');
    VendorSync::syncImages(['284096']);

    $product = VendorSync::product('SJ', '284096');

    expect($report->created)->toBe(1)
        ->and(contractShape($product))->toEqual($expected)
        ->and($product->defaultUrl->slug)->toStartWith('safety-jogger-ligero');

    // Images: per colour, HIRES is main, HR01.. are details, plus the outsole.
    $navy = $product->getMedia('images')->filter(fn ($media): bool => $media->getCustomProperty('colour') === 'Navy')->values();

    expect($product->getMedia('images'))->toHaveCount(15)
        ->and($navy->map(fn ($media): string => $media->getCustomProperty('role'))->all())->toBe(['main', 'detail', 'detail', 'detail', 'outsole'])
        ->and($navy->first()->getCustomProperty('source_url'))->toEndWith('LIGERO-NAV-HIRES.PNG')
        ->and($navy->first()->getCustomProperty('sha256'))->toHaveLength(64)
        ->and(sjVariant('SJ-1492552')->images)->toHaveCount(5)
        ->and(sjVariant('SJ-1492552')->images->first()->getCustomProperty('role'))->toBe('main');

    // The raw payload keeps the 360-degree frames for a later spin feature.
    expect(VendorRawRecord::query()->sole()->payload['colors'][0]['images']['360'])->toHaveCount(24);
});

it('imports the product list in scope and skips the rest as out of scope', function (): void {
    [$list, $details] = sjCatalogue();
    VendorSync::fakeHttp($list, $details);

    $report = VendorSync::import('SJ');

    expect($report->status)->toBe('succeeded')
        ->and($report->seen)->toBe(7)
        ->and($report->created)->toBe(5)
        ->and($report->outOfScope)->toBe(2)
        ->and($report->errors)->toBe(0)
        ->and($report->tallies['out_of_scope'])->toBe(['article_type' => 1, 'no_en_iso_norm' => 1])
        ->and(VendorProduct::query()->pluck('vendor_product_id')->sort()->values()->all())
        ->toBe(['215754', '279074', '284096', '296233', '407407']);

    $bestboot = VendorSync::product('SJ', '215754');
    $tactic = VendorSync::product('SJ', '279074');
    $ligero = VendorSync::product('SJ', '284096');

    // BESTBOOT: high_boot with EN ISO 20345 is a zero rated protective boot.
    $sizes = $bestboot->variants->map(fn (ProductVariant $variant): string => $variant->values->firstWhere('option.handle', 'shoe_size')->translate('name'))
        ->sortBy(fn (string $size): float => (float) $size)->values()->all();

    expect(sjTax($bestboot))->toBe(['zero-protective'])
        ->and($sizes)->toBe(['3.5', '4', '5', '6', '6.5', '7', '8', '9', '10', '10.5', '11', '12'])
        ->and($bestboot->attr('safety_class'))->toBe('S3')
        ->and($bestboot->attr('cut'))->toBe('High')
        ->and($bestboot->attr('toecap'))->toBe('Steel')
        ->and($bestboot->attr('standards'))->toBe(['ASTM F2413:2024', 'EN ISO 20345:2022+A1:2024'])
        ->and($bestboot->attr('features'))->toBe(['SR', 'SC', 'LG', 'FO', 'CI'])
        ->and($bestboot->translateAttribute('name'))->toBe('BESTBOOT')
        ->and($bestboot->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('safety-boots')->id]);

    // RRP 74.30 always includes 20% VAT: 61.92 ex VAT. Cost 29.30 x 1.5 = 43.95.
    $price = sjVariant('SJ-839366')->prices->sole();

    expect($price->getRawOriginal('price'))->toBe(4395)
        ->and($price->getRawOriginal('compare_price'))->toBe(6192);

    // TACTIC is occupational (EN ISO 20347 only) and Ligero is a low shoe.
    expect(sjTax($tactic))->toBe(['standard'])
        ->and($tactic->attr('safety_class'))->toBe('OB')
        ->and($tactic->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('occupational-footwear')->id])
        ->and($tactic->attr('upper'))->toBe('Textile/Full Grain Leather')
        ->and(sjTax($ligero))->toBe(['standard'])
        ->and($ligero->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('safety-shoes')->id]);

    // No RRP: uncapped, no compare price.
    $uncapped = sjVariant('SJ-40740700')->prices->sole();

    expect($uncapped->getRawOriginal('price'))->toBe(43590)
        ->and($uncapped->getRawOriginal('compare_price'))->toBeNull()
        ->and(VendorSync::product('SJ', '296233')->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('occupational-footwear')->id])
        ->and($report->tallies)->not->toHaveKey('unknown_feature');

    // Ligero's related products are not imported, so there is no cross-sell yet.
    expect(VendorProduct::query()->where('vendor_product_id', '284096')->sole()->related_ids)->toBe(['276147', '342962']);
});

it('skips unchanged products on the next run and reprocesses them with force', function (): void {
    [$list, $details] = sjCatalogue();
    VendorSync::fakeHttp($list, $details);
    VendorSync::import('SJ');

    // A different stock figure in the detail is not a catalogue change.
    $details[284096]['colors'][0]['skus'][0]['stock'] = 1;
    VendorSync::fakeHttp($list, $details);

    $second = VendorSync::import('SJ');
    $forced = VendorSync::import('SJ', force: true);

    expect($second->unchanged)->toBe(5)
        ->and($second->created + $second->updated)->toBe(0)
        ->and($forced->updated)->toBe(5);
});

it('reports missing data instead of guessing', function (): void {
    $list = [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 271323], ['id' => 5]];
    $details = [
        // Unmapped category: draft.
        1 => VendorSync::detail(1, 'CLOGGY', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011'], model: 'wader'),
        // Unknown EU size: that SKU is not created; the product is not drafted for it.
        2 => VendorSync::detail(2, 'BIGFOOT', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011'], size: '52'),
        // Cost above RRP: stock 0.
        3 => VendorSync::detail(3, 'PRICEY', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011'], cost: 80.0, rrp: 60.0),
        // No cost: nothing can be priced.
        4 => VendorSync::detail(4, 'NOCOST', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011'], cost: null),
        // The unauthenticated response: no prices, no descriptions.
        271323 => VendorSync::json('SafetyJogger/product-271323.json'),
        // id 5 has no detail: the API answers 404.
    ];

    $bigfoot = $details[2];
    $bigfoot['colors'][0]['skus'][] = [...$bigfoot['colors'][0]['skus'][0], 'id' => 201, 'sizeRange' => '42', 'eanCode' => '5000099'];
    $details[2] = $bigfoot;

    VendorSync::fakeHttp($list, $details);

    $report = VendorSync::import('SJ');
    $messages = implode("\n", $report->messages);

    $clog = VendorProduct::query()->where('vendor_product_id', '1')->sole();
    $big = VendorProduct::query()->where('vendor_product_id', '2')->sole();

    expect($report->created)->toBe(3)
        ->and($report->errors)->toBe(3)
        ->and($clog->draft_reasons)->toContain(DraftReason::UNMAPPED_CATEGORY)
        ->and(Product::query()->find($clog->product_id)->status)->toBe('draft')
        ->and(Product::query()->find($clog->product_id)->collections)->toHaveCount(0)
        ->and($big->draft_reasons)->toBe([DraftReason::NO_IMAGE])
        ->and($report->tallies['unknown_size'])->toBe(['52' => 1])
        ->and(ProductVariant::query()->where('sku', 'SJ-200')->exists())->toBeFalse()
        ->and(sjVariant('SJ-201')->stock)->toBe(10)
        ->and(sjVariant('SJ-300')->stock)->toBe(0)
        ->and(VendorOffer::query()->where('vendor_sku', '300')->sole()->sellable)->toBeFalse()
        ->and(VendorProduct::query()->whereIn('vendor_product_id', ['4', '271323', '5'])->count())->toBe(0)
        ->and($messages)->toContain("model 'wader'")
        ->and($messages)->toContain('BIGFOOT [2]: 1 SKU with a size that is not in the size chart (EU 52); not imported (SKUs: 200)')
        ->and($messages)->toContain('PRICEY [3]: 1 SKU with cost at or above RRP; stock set to 0 (SKUs: 300)')
        ->and($messages)->toContain('NOCOST [4]: 1 SKU with no cost; not imported (SKUs: 400)')
        ->and($messages)->toContain('failed with status 404');

    // Images of a product drafted by a rule do not publish it.
    VendorSync::syncImages(['1']);

    expect(Product::query()->find($clog->product_id)->status)->toBe('draft')
        ->and(Product::query()->find($clog->product_id)->getMedia('images'))->toHaveCount(1);
});

it('keeps a product\'s other images when one download fails', function (): void {
    $ligero = VendorSync::json('SafetyJogger/product-284096.json');
    VendorSync::fakeHttp([['id' => 284096]], [284096 => $ligero], brokenImages: ['LIGERO-NAV-HR02']);

    VendorSync::import('SJ');
    $link = VendorProduct::query()->sole();

    $result = app(ProductImageSync::class)->sync($link);

    expect($result->failed)->toHaveCount(1)
        ->and($result->failed[0])->toContain('LIGERO-NAV-HR02')
        ->and($result->failed[0])->toContain('download failed')
        ->and(VendorSync::product('SJ', '284096')->getMedia('images'))->toHaveCount(14)
        ->and(VendorSync::product('SJ', '284096')->status)->toBe('published')
        ->and($link->refresh()->images_synced_at)->not->toBeNull();

    // Marked as incomplete, so the next run tries the missing image again.
    $incomplete = $link->refresh()->image_urls_hash;
    expect($incomplete)->not->toBeNull();

    // Nothing is downloaded twice: only the missing image is fetched again.
    VendorSync::fakeHttp([['id' => 284096]], [284096 => $ligero]);
    $again = app(ProductImageSync::class)->sync($link->refresh());

    expect($again->downloaded)->toBe(1)
        ->and(VendorSync::product('SJ', '284096')->getMedia('images'))->toHaveCount(15)
        ->and($link->refresh()->image_urls_hash)->not->toBe($incomplete);
});

it('updates every offer from the stock feed by skuId', function (): void {
    [$list, $details] = sjCatalogue();
    $stock = VendorSync::json('SafetyJogger/stock.json');
    VendorSync::fakeHttp($list, $details, $stock);
    VendorSync::import('SJ');

    $report = app(StockSync::class)->run('SJ');

    $feed = collect($stock)->keyBy(fn (array $row): string => (string) $row['skuId']);
    $offers = VendorOffer::query()
        ->whereIn('product_variant_id', ProductVariant::query()->whereIn('product_id', [
            VendorSync::product('SJ', '284096')->id, VendorSync::product('SJ', '279074')->id, VendorSync::product('SJ', '215754')->id,
        ])->select('id'))
        ->get();

    expect($report->status)->toBe('succeeded')
        ->and($report->seen)->toBe(count($stock))
        ->and($offers)->toHaveCount(62);

    foreach ($offers as $offer) {
        expect($feed->has($offer->vendor_sku))->toBeTrue()
            ->and($offer->stock)->toBe($feed[$offer->vendor_sku]['stock'])
            ->and(ProductVariant::query()->find($offer->product_variant_id)->stock)->toBe($feed[$offer->vendor_sku]['stock']);
    }

    // The stock feed wins over the product detail (613 there, 611 here).
    expect(sjVariant('SJ-1492622')->stock)->toBe(611);
});

it('matches stock rows on skuId so rows sharing an EAN do not affect each other', function (): void {
    $a = VendorSync::detail(9372, 'ALPHA', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011']);
    $b = VendorSync::detail(12941, 'BRAVO', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011']);
    $a['colors'][0]['skus'][0] = [...$a['colors'][0]['skus'][0], 'id' => 937259, 'eanCode' => '5412252784822'];
    $b['colors'][0]['skus'][0] = [...$b['colors'][0]['skus'][0], 'id' => 1294173, 'eanCode' => '5412252784822'];

    VendorSync::fakeHttp([['id' => 9372], ['id' => 12941]], [9372 => $a, 12941 => $b], VendorSync::json('SafetyJogger/stock.json'));
    VendorSync::import('SJ');

    app(StockSync::class)->run('SJ');

    expect(sjVariant('SJ-937259')->stock)->toBe(46)
        ->and(sjVariant('SJ-1294173')->stock)->toBe(76);
});

it('reindexes only products whose in-stock state changed and never restocks an unsellable offer', function (): void {
    $steady = VendorSync::detail(1, 'STEADY', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011']);
    $soldOut = VendorSync::detail(2, 'SOLDOUT', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011']);
    $pricey = VendorSync::detail(3, 'PRICEY', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011'], cost: 80.0, rrp: 60.0);

    VendorSync::fakeHttp([['id' => 1], ['id' => 2], ['id' => 3]], [1 => $steady, 2 => $soldOut, 3 => $pricey], [
        ['skuId' => 100, 'ean' => 'x', 'stock' => 25],
        ['skuId' => 200, 'ean' => 'x', 'stock' => 0],
        ['skuId' => 300, 'ean' => 'x', 'stock' => 40],
        ['skuId' => 999999, 'ean' => '', 'stock' => 5],
    ]);
    VendorSync::import('SJ');

    $report = app(StockSync::class)->run('SJ');

    expect($report->reindexedProductIds)->toBe([VendorSync::product('SJ', '2')->id])
        ->and($report->updated)->toBe(2)
        ->and(sjVariant('SJ-100')->stock)->toBe(25)
        ->and(sjVariant('SJ-200')->stock)->toBe(0)
        // Cost above RRP: the feed's 40 is recorded on the offer, not sold.
        ->and(sjVariant('SJ-300')->stock)->toBe(0)
        ->and(VendorOffer::query()->where('vendor_sku', '300')->sole()->stock)->toBe(40);
});

it('updates 1,000 offers in under five seconds', function (): void {
    $detail = VendorSync::detail(1, 'BULK', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2011']);
    VendorSync::fakeHttp([['id' => 1]], [1 => $detail]);
    VendorSync::import('SJ');

    $template = (array) DB::table((new ProductVariant)->getTable())->where('sku', 'SJ-100')->first();
    $offer = (array) DB::table('vendor_offers')->where('vendor_sku', '100')->first();
    unset($template['id'], $offer['id']);

    $variants = [];

    foreach (range(1, 1000) as $index) {
        $variants[] = [...$template, 'sku' => 'SJ-B'.$index];
    }

    DB::table((new ProductVariant)->getTable())->insert($variants);

    $ids = DB::table((new ProductVariant)->getTable())->where('sku', 'like', 'SJ-B%')->pluck('id', 'sku');
    $stock = [];
    $offers = [];

    foreach (range(1, 1000) as $index) {
        $offers[] = [...$offer, 'product_variant_id' => $ids['SJ-B'.$index], 'vendor_sku' => 'B'.$index];
        $stock[] = ['skuId' => 'B'.$index, 'ean' => '', 'stock' => $index];
    }

    DB::table('vendor_offers')->insert($offers);
    VendorSync::fakeHttp(stock: $stock);

    $started = microtime(true);
    $report = app(StockSync::class)->run('SJ');
    $seconds = microtime(true) - $started;

    expect($report->updated)->toBe(999)
        ->and($seconds)->toBeLessThan(5.0)
        ->and(sjVariant('SJ-B1000')->stock)->toBe(1000)
        ->and(VendorOffer::query()->where('vendor_sku', 'B500')->sole()->stock)->toBe(500)
        // Every variant was already in stock: nothing to reindex.
        ->and($report->reindexedProductIds)->toBe([]);
});

it('says so when a vendor has no stock feed', function (): void {
    $report = app(StockSync::class)->run('FS');

    expect($report->status)->toBe('aborted')
        ->and($report->messages[0])->toContain('updated by its catalogue run');
});

it('runs the catalogue command as a dry run without writing', function (): void {
    [$list, $details] = sjCatalogue();
    VendorSync::fakeHttp($list, $details);

    $this->artisan('vendorsync:catalogue', ['vendor' => 'SJ', '--dry-run' => true])
        ->expectsOutputToContain('DRY RUN, nothing was written')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0);

    // Only read requests were made, and the report holds no credentials.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' || str_ends_with($request->url(), '/login'));

    $reportFile = File::glob(VendorSync::path('reports').'/sj-catalogue-dry-run-*.json')[0];

    expect((string) file_get_contents($reportFile))->not->toContain('test-password')
        ->and((string) file_get_contents($reportFile))->not->toContain('test-hash');
});

it('queues image jobs after a catalogue run and shows the status', function (): void {
    $ligero = VendorSync::json('SafetyJogger/product-284096.json');
    $ligero['colors'] = [$ligero['colors'][0]];
    VendorSync::fakeHttp([['id' => 284096]], [284096 => $ligero]);

    $this->artisan('vendorsync:catalogue', ['vendor' => 'SJ'])
        ->expectsOutputToContain('Queued 1 image jobs')
        ->assertSuccessful();

    expect(VendorSync::product('SJ', '284096')->status)->toBe('published')
        ->and(VendorSync::product('SJ', '284096')->getMedia('images'))->toHaveCount(5);

    $this->artisan('vendorsync:status')
        ->expectsOutputToContain('catalogue')
        ->assertSuccessful();

    expect(SyncRun::query()->where('type', 'images')->sole()->status)->toBe('succeeded');
});
