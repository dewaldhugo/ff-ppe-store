<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Urls\GenerateProductSlug;
use Database\Seeders\FixtureCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Brand;
use Lunar\Models\Product;
use Lunar\Models\ProductAssociation;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;
use Lunar\Models\Url;

function productBySlug(string $slug): Product
{
    return Url::query()
        ->where('element_type', Product::morphName())
        ->where('slug', $slug)
        ->sole()
        ->element;
}

function variantBySku(string $sku): ProductVariant
{
    return ProductVariant::query()->where('sku', $sku)->sole();
}

// The foundation data and catalogue.json are seeded once per test run (see
// Tests\TestCase).

describe('catalogue.json', function (): void {
    it('creates all eight products as published with their brands', function (): void {
        expect(Product::query()->where('status', 'published')->count())->toBe(8)
            ->and(ProductVariant::query()->count())->toBe(80)
            ->and(Brand::query()->orderBy('name')->pluck('name')->all())
            ->toBe(['Amblers Safety', 'Centek', 'GBS', 'Magnum', 'Maxguard', 'Safety Jogger']);
    });

    it('builds product slugs from brand and name', function (string $slug): void {
        expect(productBySlug($slug))->toBeInstanceOf(Product::class);
    })->with([
        'safety-jogger-ligero', 'maxguard-sx310', 'magnum-classic', 'amblers-safety-fs9',
        'amblers-safety-fs65', 'centek-fs30c', 'gbs-eva', 'gbs-exeter-slipper',
    ]);

    it('stores product attributes in their contract types', function (): void {
        $ligero = productBySlug('safety-jogger-ligero');

        expect($ligero->translateAttribute('name'))->toBe('Ligero')
            ->and($ligero->translateAttribute('title'))->toBe('Lightweight ESD Safety Shoes')
            ->and($ligero->attr('safety_class'))->toBe('S1P')
            ->and($ligero->attr('features'))->toBe(['SRC', 'CI', 'ESD', 'METAL_FREE'])
            ->and($ligero->attr('toecap'))->toBe('Nano Carbon')
            ->and($ligero->attr('video_url'))->toBe('https://www.youtube.com/embed/fux4KhcHMLw')
            ->and($ligero->brand->name)->toBe('Safety Jogger')
            ->and($ligero->productType->id)->toBe(CatalogueKeys::productType('safety-footwear')->id);
    });

    it('leaves out null and empty attribute values', function (): void {
        $eva = productBySlug('gbs-eva');

        expect($eva->attribute_data->has('safety_class'))->toBeFalse()
            ->and($eva->attribute_data->has('features'))->toBeFalse()
            ->and($eva->attribute_data->has('short_description'))->toBeFalse();
    });

    it('writes a variant exactly as the contract describes', function (): void {
        $variant = variantBySku('SJ-1492552');
        $price = $variant->prices()->sole();

        expect($variant->ean)->toBe('5400812979016')
            ->and($variant->stock)->toBe(206)
            ->and($variant->purchasable)->toBe('in_stock')
            ->and($variant->shippable)->toBeTrue()
            ->and($variant->tax_class_id)->toBe(CatalogueKeys::taxClass('standard')->id)
            ->and($variant->attr('eu_size'))->toBe('38')
            ->and((float) $variant->weight_value)->toBe(0.439)
            ->and($variant->weight_unit)->toBe('kg')
            ->and($variant->values->map(fn ($value) => $value->option->handle.'='.$value->translate('name'))->sort()->values()->all())
            ->toBe(['colour=Navy', 'shoe_size=5'])
            ->and($price->price->value)->toBe(6890)
            ->and($price->compare_price->value)->toBe(7650)
            ->and($price->currency->code)->toBe('GBP')
            ->and($price->customerGroup->handle)->toBe('retail')
            ->and($price->min_quantity)->toBe(1);
    });

    it('stores box dimensions in millimetres when the vendor supplies them', function (): void {
        $variant = variantBySku('FS-01049-00848-03');

        expect((float) $variant->length_value)->toBe(335.0)
            ->and($variant->length_unit)->toBe('mm')
            ->and((float) $variant->width_value)->toBe(245.0)
            ->and((float) $variant->height_value)->toBe(135.0);
    });

    it('leaves compare price null when the fixture has none', function (): void {
        $price = DB::table(config('lunar.database.table_prefix').'prices')
            ->where('priceable_id', variantBySku('SJ-1342249')->id)
            ->sole();

        expect($price->compare_price)->toBeNull();
    });

    it('assigns the tax class from the fixture', function (string $sku, string $taxClass): void {
        expect(variantBySku($sku)->tax_class_id)->toBe(CatalogueKeys::taxClass($taxClass)->id);
    })->with([
        ['FS-01049-00848-03', 'zero-protective'],
        ['FS-00345-00772-03', 'zero-protective'],
        ['FS-01055-00821-03', 'standard'],
        ['FS-00048-00480-03', 'standard'],
    ]);

    it('puts products in their categories and ranges', function (string $slug, array $handles): void {
        $actual = productBySlug($slug)->collections
            ->map(fn ($collection) => $collection->defaultUrl->slug)
            ->sort()->values()->all();

        expect($actual)->toBe($handles);
    })->with([
        ['safety-jogger-ligero', ['safety-shoes']],
        ['magnum-classic', ['occupational-footwear']],
        ['amblers-safety-fs9', ['clearance', 'safety-boots']],
        ['amblers-safety-fs65', ['clearance', 'safety-shoes']],
        ['gbs-eva', ['clearance', 'slippers']],
    ]);

    it('writes vendor identity to vendor_products, not to Lunar', function (): void {
        $row = DB::table('vendor_products')
            ->join('vendors', 'vendors.id', '=', 'vendor_products.vendor_id')
            ->where('product_id', productBySlug('safety-jogger-ligero')->id)
            ->sole();

        expect($row->code)->toBe('SJ')
            ->and($row->name)->toBe('Safety Jogger')
            ->and($row->vendor_product_id)->toBe('284096')
            ->and($row->commodity_code)->toBe('6404199000')
            ->and(DB::table('vendors')->orderBy('code')->pluck('code')->all())->toBe(['FS', 'SJ'])
            ->and(DB::table('vendor_products')->count())->toBe(8);
    });

    it('writes the vendor wording and cost to vendor_offers', function (): void {
        $offer = DB::table('vendor_offers')->where('product_variant_id', variantBySku('SJ-1492552')->id)->sole();

        expect($offer->vendor_sku)->toBe('1492552')
            ->and($offer->vendor_product_name)->toBe('LIGERO')
            ->and($offer->vendor_colour)->toBe('navy')
            ->and($offer->vendor_size)->toBe('38')
            ->and($offer->ean)->toBe('5400812979016')
            ->and($offer->cost_price)->toBe(4593)
            ->and($offer->stock)->toBe(206)
            ->and($offer->last_seen_at)->not->toBeNull()
            ->and(DB::table('vendor_offers')->count())->toBe(80);
    });

    it('keeps cost null where the fixture has no cost', function (): void {
        $offer = DB::table('vendor_offers')->where('product_variant_id', variantBySku('SJ-1342249')->id)->sole();

        expect($offer->cost_price)->toBeNull();
    });

    it('shares colour and size option values across products', function (): void {
        $black = ProductOptionValue::query()
            ->whereHas('option', fn ($query) => $query->where('handle', 'colour'))
            ->where('name->en', 'Black')
            ->sole();

        $size = ProductOptionValue::query()
            ->whereHas('option', fn ($query) => $query->where('handle', 'shoe_size'))
            ->where('name->en', '10')
            ->sole();

        expect($black->meta['hex'])->toBe('000000')
            ->and($black->variants()->distinct()->count('product_id'))->toBe(5)
            ->and((float) $size->meta['sort'])->toBe(10.0);
    });

    it('links both options to every product', function (): void {
        expect(productBySlug('gbs-eva')->productOptions->pluck('handle')->all())->toBe(['colour', 'shoe_size']);
    });

    it('creates one image per colour and links it to the variants of that colour', function (): void {
        $ligero = productBySlug('safety-jogger-ligero');
        $images = $ligero->getMedia('images');

        expect($images)->toHaveCount(3)
            ->and($images->map(fn ($media) => $media->getCustomProperty('colour'))->all())->toBe(['Navy', 'Black', 'Orange'])
            ->and($images->map(fn ($media) => $media->getCustomProperty('role'))->unique()->all())->toBe(['main'])
            ->and($images->first()->disk)->toBe('products')
            ->and($ligero->thumbnail->getCustomProperty('colour'))->toBe('Navy');

        $blackVariant = variantBySku('SJ-1492520');

        expect($blackVariant->images)->toHaveCount(1)
            ->and($blackVariant->images->first()->getCustomProperty('colour'))->toBe('Black')
            ->and((bool) $blackVariant->images->first()->pivot->primary)->toBeTrue()
            ->and($blackVariant->getThumbnail()->getCustomProperty('colour'))->toBe('Black');
    });

    it('generates the contract conversions as WebP', function (): void {
        $media = productBySlug('gbs-eva')->getFirstMedia('images');

        foreach (['thumb', 'medium', 'large', 'small'] as $conversion) {
            expect($media->hasGeneratedConversion($conversion))->toBeTrue()
                ->and($media->getUrl($conversion))->toEndWith('.webp');
        }
    });

    it('creates nothing when run a second time', function (): void {
        $counts = fn (): array => [
            Product::query()->count(),
            ProductVariant::query()->count(),
            ProductOptionValue::query()->count(),
            DB::table('vendor_offers')->count(),
            DB::table('vendor_products')->count(),
            DB::table('media')->count(),
            Url::query()->count(),
        ];

        $before = $counts();
        $this->seed(FixtureCatalogueSeeder::class);

        expect($counts())->toBe($before);
    });
});

it('turns related vendor product IDs into cross-sell associations', function (): void {
    app(FixtureCatalogueSeeder::class)->run(base_path('tests/Fixtures/related-products.json'));

    $first = productBySlug('safety-jogger-duplicate');
    $second = productBySlug('safety-jogger-duplicate-2');

    $association = ProductAssociation::query()->where('product_parent_id', $first->id)->sole();

    // The unknown related ID (999999) is skipped.
    expect($association->product_parent_id)->toBe($first->id)
        ->and($association->product_target_id)->toBe($second->id)
        ->and($association->type)->toBe('cross-sell')
        ->and($first->associations()->crossSell()->count())->toBe(1);
});

it('appends a numeric suffix to colliding slugs in order of creation', function (): void {
    app(FixtureCatalogueSeeder::class)->run(base_path('tests/Fixtures/related-products.json'));

    $generate = app(GenerateProductSlug::class);

    expect($generate->handle('Safety Jogger', 'Duplicate'))->toBe('safety-jogger-duplicate-3')
        ->and($generate->handle('Safety Jogger', 'Brand New'))->toBe('safety-jogger-brand-new');
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    app(FixtureCatalogueSeeder::class)->run();
})->throws(RuntimeException::class, 'local and testing environments');
