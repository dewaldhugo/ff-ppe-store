<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Import\RunReport;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Support\Carbon;
use Lunar\Models\Product;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;
use Tests\Support\VendorSync;

/*
 * Clothing, gloves, head protection, fall protection, overshoes and
 * accessories: sized on the garment scale, standard rate always, none of the
 * footwear-only attributes. See CLAUDE.md (data-contract.md, Product types
 * and options) and docs/vendorsync.md.
 */

function variantOf(string $sku): ProductVariant
{
    return ProductVariant::query()->where('sku', $sku)->sole();
}

function sizeValue(ProductVariant $variant, string $handle): ?ProductOptionValue
{
    return $variant->values->firstWhere('option.handle', $handle);
}

describe('Safety Jogger', function (): void {
    beforeEach(fn () => VendorSync::boot());

    it('maps a workwear product to its own leaf, sized on the garment scale', function (): void {
        $detail = VendorSync::detail(500001, 'OAKTSM', 'Safety Jogger', 'Workwear', []);
        $detail['workwear_product_category'] = [['name' => 'tshirt_polo', 'value' => 'T-shirts & polo shirts']];
        $detail['colors'][0]['skus'][0]['sizeRange'] = 'Med';
        VendorSync::fakeHttp([['id' => 500001]], [500001 => $detail]);

        $report = VendorSync::import('SJ');
        $product = VendorSync::product('SJ', '500001');
        $variant = $product->variants->sole();

        expect($report->created)->toBe(1)
            ->and(VendorProduct::query()->sole()->draft_reasons)->not->toContain(DraftReason::UNMAPPED_CATEGORY)
            ->and(CatalogueKeys::handleFor(KeyType::ProductType, $product->productType))->toBe('workwear')
            ->and($product->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('tshirts-polos-shirts')->id])
            ->and($product->attr('safety_class'))->toBeNull()
            // An empty ListField is never stored at all (`filled([])` is false).
            ->and($product->attr('standards'))->toBeNull()
            ->and($product->productOptions->pluck('handle')->sort()->values()->all())->toBe(['colour', 'garment_size'])
            ->and(sizeValue($variant, 'garment_size')?->translate('name'))->toBe('M')
            ->and(sizeValue($variant, 'shoe_size'))->toBeNull()
            ->and($variant->attr('eu_size'))->toBeNull()
            ->and(CatalogueKeys::handleFor(KeyType::TaxClass, $variant->taxClass))->toBe('standard');
    });

    it('maps head protection by its subcategory and gloves and fall protection by article type alone', function (): void {
        $hardHat = VendorSync::detail(500002, 'KANHASWS', 'Safety Jogger', 'Head_protection', []);
        $hardHat['headprotection_product_subcategory'] = [['name' => 'hard_hats', 'value' => 'Hard hats']];

        $gloves = VendorSync::detail(500003, 'ECOPROTECT', 'Safety Jogger', 'Gloves', []);
        $gloves['colors'][0]['skus'][0]['sizeRange'] = '10';

        $fallProtection = VendorSync::detail(500004, 'EH112003', 'Safety Jogger', 'Fall_protection', []);

        VendorSync::fakeHttp(
            [['id' => 500002], ['id' => 500003], ['id' => 500004]],
            [500002 => $hardHat, 500003 => $gloves, 500004 => $fallProtection],
        );

        VendorSync::import('SJ');

        expect(VendorSync::product('SJ', '500002')->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('hard-hats-helmets')->id])
            ->and(VendorSync::product('SJ', '500003')->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('gloves')->id])
            ->and(sizeValue(variantOf('SJ-50000300'), 'garment_size')?->translate('name'))->toBe('10')
            ->and(VendorSync::product('SJ', '500004')->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('fall-protection')->id]);
    });

    it('skips a multi-unit SKU outside footwear too, and reports it', function (): void {
        $detail = VendorSync::detail(500005, 'ECOPSHIELD', 'Safety Jogger', 'Gloves', []);
        $detail['colors'][0]['skus'][0]['buyPer'] = 12;
        VendorSync::fakeHttp([['id' => 500005]], [500005 => $detail]);

        $report = VendorSync::import('SJ');

        expect($report->created)->toBe(0)
            ->and($report->errors)->toBe(1)
            ->and(Product::query()->count())->toBe(0)
            ->and(implode("\n", $report->messages))->toContain('not sold per single pair');
    });
});

describe('Footsure', function (): void {
    beforeEach(function (): void {
        VendorSync::boot();
        VendorSync::fakeHttp();
        Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));
    });

    /**
     * One synthetic Footsure export row for a non-footwear product.
     *
     * @param  array<string, string>  $set
     * @return array<string, string>
     */
    function garmentRow(array $template, array $set): array
    {
        static $barcode = 6100000000000;

        $row = [
            ...$template,
            'ProductID' => '950001',
            'Family Product Reference' => '950001-1',
            'Short Product Name' => 'Test Jacket',
            'Long Product Name' => 'Test Jacket',
            'Group Class' => 'Jackets',
            'Brand' => 'Helly Hansen Workwear',
            'Colour Code' => 'BLACK',
            'Colour Extention' => 'Black',
            'Safety Standard' => 'Not Set',
            'Safety Cat' => '',
            'Safety' => '',
            'VAT' => '0.2000',
            'Trade Price' => '20.00',
            'SRP' => '55.00',
            'Stock' => '10',
            'Weight' => '400',
            'Length' => '300',
            'Width' => '200',
            'Height' => '50',
            'Product Status' => 'Live',
            'Colour Status' => 'Live',
            'Country Of Origin' => 'China',
            'Waterproof' => '0',
            'Size' => 'Med',
            'UK Size' => '',
            'EU Size' => '',
            'Fastening' => 'Zip',
            'Image1' => 'http://ts-images.gardinerbros.co.uk/test/garment.jpg',
            'Image2' => '', 'Image3' => '', 'Image4' => '', 'Image5' => '', 'Image6' => '', 'Image7' => '', 'Image8' => '',
            ...$set,
        ];

        $row['Barcode'] = array_key_exists('Barcode', $set) ? $set['Barcode'] : (string) ++$barcode;
        $row['Product Reference'] ??= '';

        return $row;
    }

    /**
     * @param  callable(array<string, string>): list<array<string, string>>  $make
     */
    function importGarmentRows(callable $make): RunReport
    {
        VendorSync::footsureFile(fn (array $rows): array => $make($rows[0]));

        return VendorSync::import('FS');
    }

    it('maps a jacket to garment_size, standard rate, with no footwear attributes', function (): void {
        $report = importGarmentRows(fn (array $t): array => [
            garmentRow($t, ['Product Reference' => 'A-1']),
        ]);

        $product = VendorSync::product('FS', '950001');
        $variant = $product->variants->sole();

        expect($report->created)->toBe(1)
            ->and(CatalogueKeys::handleFor(KeyType::ProductType, $product->productType))->toBe('workwear')
            ->and($product->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('jackets-fleeces')->id])
            ->and($product->attr('safety_class'))->toBeNull()
            // An empty ListField is never stored at all (`filled([])` is false).
            ->and($product->attr('features'))->toBeNull()
            ->and($product->attr('fastening'))->toBeNull()
            ->and($product->productOptions->pluck('handle')->sort()->values()->all())->toBe(['colour', 'garment_size'])
            ->and(sizeValue($variant, 'garment_size')?->translate('name'))->toBe('M')
            ->and($variant->attr('eu_size'))->toBeNull()
            ->and(CatalogueKeys::handleFor(KeyType::TaxClass, $variant->taxClass))->toBe('standard');
    });

    it('normalises vendor size wording and keeps an unrecognised one verbatim', function (): void {
        importGarmentRows(fn (array $t): array => [
            garmentRow($t, ['Product Reference' => 'B-1', 'Family Product Reference' => '950001-1', 'Colour Code' => 'BLACK', 'Size' => 'Sml']),
            garmentRow($t, ['Product Reference' => 'B-2', 'Family Product Reference' => '950001-1', 'Colour Code' => 'BLACK', 'Size' => 'Xsl']),
            garmentRow($t, ['Product Reference' => 'B-3', 'Family Product Reference' => '950001-1', 'Colour Code' => 'BLACK', 'Size' => 'W42L30']),
        ]);

        expect(sizeValue(variantOf('FS-B-1'), 'garment_size')?->translate('name'))->toBe('S')
            ->and(sizeValue(variantOf('FS-B-2'), 'garment_size')?->translate('name'))->toBe('XS')
            ->and(sizeValue(variantOf('FS-B-3'), 'garment_size')?->translate('name'))->toBe('W42L30');
    });

    it('gives an item with no real size One Size', function (): void {
        importGarmentRows(fn (array $t): array => [
            garmentRow($t, ['Product Reference' => 'C-1', 'Group Class' => 'Eyewear', 'Size' => 'Itm', 'UK Size' => 'Itm', 'EU Size' => 'Itm']),
        ]);

        expect(sizeValue(variantOf('FS-C-1'), 'garment_size')?->translate('name'))->toBe('One Size')
            ->and(VendorSync::product('FS', '950001')->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('eye-face-protection')->id]);
    });

    it('sizes Insoles as footwear although its collection is an accessory', function (): void {
        importGarmentRows(fn (array $t): array => [
            garmentRow($t, ['Product Reference' => 'D-1', 'Group Class' => 'Insoles', 'UK Size' => '8', 'EU Size' => '42', 'Size' => '8']),
        ]);

        $product = VendorSync::product('FS', '950001');
        $variant = $product->variants->sole();

        expect(CatalogueKeys::handleFor(KeyType::ProductType, $product->productType))->toBe('accessories')
            ->and($product->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('insoles-laces-shoe-care')->id])
            ->and($product->productOptions->pluck('handle')->sort()->values()->all())->toBe(['colour', 'shoe_size'])
            ->and(sizeValue($variant, 'shoe_size')?->translate('name'))->toBe('8')
            ->and($variant->attr('eu_size'))->toBe('42');
    });
});
