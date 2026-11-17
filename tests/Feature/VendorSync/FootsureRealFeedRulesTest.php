<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\VendorSync\Exceptions\ImageSyncFailed;
use App\Domain\VendorSync\Images\ProductImageSync;
use App\Domain\VendorSync\Images\SyncProductImages;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Import\RunReport;
use App\Domain\VendorSync\Models\VendorProduct;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\Support\VendorSync;

/*
 * Rules that came out of the first look at the real Footsure feed. The rows
 * are synthetic, modelled on the real file: the real file is never a test
 * fixture.
 */

beforeEach(function (): void {
    VendorSync::boot();
    VendorSync::fakeHttp();
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));
});

/**
 * One synthetic export row. Defaults describe a zero rated plimsoll.
 *
 * @param  array<string, string>  $set
 * @return array<string, string>
 */
function fsRow(array $template, array $set): array
{
    static $barcode = 6000000000000;

    $row = [
        ...$template,
        'ProductID' => '900001',
        'Family Product Reference' => '900001-1',
        'Short Product Name' => 'Test Plimsoll',
        'Long Product Name' => 'Test Plimsoll',
        'Group Class' => 'Lace Plimsolls',
        'Brand' => 'Mirak',
        'Colour Code' => 'WHITE',
        'Colour Extention' => 'White',
        'Safety Standard' => 'Non Safety',
        'Safety Cat' => 'Non Safety',
        'Safety' => '',
        'VAT' => '0.0000',
        'Trade Price' => '5.00',
        'SRP' => '12.00',
        'Stock' => '10',
        'Weight' => '300',
        'Length' => '260',
        'Width' => '80',
        'Height' => '80',
        'Product Status' => 'Live',
        'Colour Status' => 'Live',
        'Country Of Origin' => 'China',
        'Waterproof' => '0',
        'Image1' => 'http://ts-images.gardinerbros.co.uk/test/one.jpg',
        'Image2' => '', 'Image3' => '', 'Image4' => '', 'Image5' => '', 'Image6' => '', 'Image7' => '', 'Image8' => '',
        ...$set,
    ];

    $row['Barcode'] = array_key_exists('Barcode', $set) ? $set['Barcode'] : (string) ++$barcode;
    $row['Product Reference'] ??= '';

    return $row;
}

/**
 * Import a file made of the given rows.
 *
 * @param  callable(array<string, string>): list<array<string, string>>  $make  Receives a template row.
 */
function importRows(callable $make): RunReport
{
    VendorSync::footsureFile(fn (array $rows): array => $make($rows[0]));

    return VendorSync::import('FS');
}

/**
 * @return array<string, float> Size name => sort, for one product.
 */
function sizesOf(string $vendorProductId): array
{
    return VendorSync::product('FS', $vendorProductId)->variants
        ->mapWithKeys(function (ProductVariant $variant): array {
            $size = $variant->values->firstWhere('option.handle', 'shoe_size');

            return [$size->translate('name') => (float) $size->meta['sort']];
        })->sort()->all();
}

it('names sizes by the EU scale and sorts them by EU size', function (): void {
    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'A-1', 'UK Size' => '6', 'Size' => '6', 'EU Size' => '23']),
        fsRow($t, ['Product Reference' => 'A-2', 'UK Size' => '6', 'Size' => '6', 'EU Size' => '39']),
        fsRow($t, ['Product Reference' => 'A-3', 'UK Size' => '1', 'Size' => '1', 'EU Size' => '33']),
        fsRow($t, ['Product Reference' => 'A-4', 'UK Size' => '2', 'Size' => '2', 'EU Size' => '35']),
        // Standard rated: the scale decides, not the VAT.
        fsRow($t, ['Product Reference' => 'A-5', 'UK Size' => '7', 'Size' => '7', 'EU Size' => '24', 'VAT' => '0.2000']),
    ]);

    // Infant 6 and junior 6 are different sizes of one colour, not a duplicate.
    expect($report->status)->toBe('succeeded')
        ->and(sizesOf('900001'))->toBe(['Kids 6' => 23.0, 'Kids 7' => 24.0, '1' => 33.0, '2' => 34.0, '6' => 39.0])
        ->and(collect($report->messages)->filter(fn ($m): bool => str_contains($m, 'repeating')))->toBeEmpty();
});

it('shows a zero rated ladies product in plain sizes', function (): void {
    importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'B-1', 'UK Size' => '2', 'EU Size' => '35', 'Group Class' => 'Classic Ladies Slippers', 'Gender' => 'Female']),
        fsRow($t, ['Product Reference' => 'B-2', 'UK Size' => '3', 'EU Size' => '36', 'Group Class' => 'Classic Ladies Slippers', 'Gender' => 'Female']),
    ]);

    expect(array_map(strval(...), array_keys(sizesOf('900001'))))->toBe(['2', '3']);
});

it('skips a zero rated row without an EU size and reports it once', function (): void {
    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'C-1', 'UK Size' => '6', 'EU Size' => '']),
        fsRow($t, ['Product Reference' => 'C-2', 'UK Size' => '7', 'EU Size' => '']),
        fsRow($t, ['Product Reference' => 'C-3', 'UK Size' => '8', 'EU Size' => '26']),
        // Standard rated without an EU size is on the plain scale and is kept.
        fsRow($t, ['Product Reference' => 'C-4', 'UK Size' => '9', 'EU Size' => '', 'VAT' => '0.2000']),
    ]);

    expect(array_map(strval(...), array_keys(sizesOf('900001'))))->toBe(['Kids 8', '9'])
        ->and($report->tallies['unknown_size'])->toBe(['no EU size at zero VAT' => 2])
        ->and(collect($report->messages)->filter(fn ($m): bool => str_contains($m, 'no EU size')))->toHaveCount(1)
        ->and($report->messages[0])->toContain('Mirak Test Plimsoll [900001]: 2 SKUs at a zero VAT rate with no EU size')
        ->and($report->messages[0])->toContain('(SKUs: C-1, C-2)');
});

it('does not create a product whose zero rated rows all lack an EU size', function (): void {
    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'D-1', 'UK Size' => '6', 'EU Size' => '']),
    ]);

    expect($report->created)->toBe(0)
        ->and($report->errors)->toBe(1)
        ->and(Product::query()->count())->toBe(0)
        ->and(implode("\n", $report->messages))->toContain('no importable variant (unknown_size)');
});

it('skips placeholder rows in one report line and keeps a real row with the same measurements', function (): void {
    $placeholder = ['Weight' => '999', 'Length' => '333', 'Width' => '222', 'Height' => '111'];

    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'E-1', 'UK Size' => '3', 'EU Size' => '36', 'Stock' => '4']),
        fsRow($t, ['Product Reference' => 'E-2', 'UK Size' => '4', 'EU Size' => '', 'Barcode' => '', 'Stock' => '0', ...$placeholder]),
        fsRow($t, ['Product Reference' => 'E-3', 'UK Size' => '5', 'EU Size' => '', 'Barcode' => '', 'Stock' => '0', ...$placeholder]),
        // Placeholder measurements but a barcode and an EU size: a real row.
        fsRow($t, ['Product Reference' => 'E-4', 'UK Size' => '6', 'EU Size' => '39', 'Stock' => '2', ...$placeholder]),
    ]);

    $kept = ProductVariant::query()->pluck('sku')->sort()->values()->all();
    $real = ProductVariant::query()->where('sku', 'FS-E-4')->sole();

    expect($kept)->toBe(['FS-E-1', 'FS-E-4'])
        ->and($real->weight_value)->toBeNull()
        ->and(collect($report->messages)->filter(fn ($m): bool => str_contains($m, 'placeholder rows')))->toHaveCount(1)
        ->and($report->messages[0])->toContain('2 placeholder rows (no barcode, no EU size, placeholder measurements); not imported')
        ->and($report->messages[0])->toContain('(SKUs: E-2, E-3)');
});

it('keeps the row with a barcode when two rows repeat a colour and size, in one report line', function (): void {
    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'F-1', 'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000']),
        fsRow($t, ['Product Reference' => 'F-2', 'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000', 'Barcode' => '', 'Weight' => '500']),
        fsRow($t, ['Product Reference' => 'F-3', 'UK Size' => '9', 'EU Size' => '43', 'VAT' => '0.2000', 'Barcode' => '', 'Weight' => '500']),
        fsRow($t, ['Product Reference' => 'F-4', 'UK Size' => '9', 'EU Size' => '43', 'VAT' => '0.2000']),
    ]);

    expect(ProductVariant::query()->pluck('sku')->sort()->values()->all())->toBe(['FS-F-1', 'FS-F-4'])
        ->and(collect($report->messages)->filter(fn ($m): bool => str_contains($m, 'repeating a colour and size')))->toHaveCount(1)
        ->and($report->messages[0])->toContain('2 SKUs repeating a colour and size (White 8, White 9); skipped')
        ->and($report->messages[0])->toContain('(SKUs: F-2, F-3)');
});

it('repairs damaged apostrophes, reports the product once, and is stable on the next run', function (): void {
    $damaged = "FS96 Women\u{FFFD}\u{FFFD}\u{FFFD}s Safety Court Shoe";
    $make = fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'G-1', 'Barcode' => '7000000000001', 'UK Size' => '4', 'EU Size' => '37', 'VAT' => '0.2000', 'Long Product Name' => $damaged, 'InspirationalCopy' => "Women\u{FFFD}\u{FFFD}\u{FFFD}s shoe, 100\u{FFFD}% leather"]),
        fsRow($t, ['Product Reference' => 'G-2', 'Barcode' => '7000000000002', 'UK Size' => '5', 'EU Size' => '38', 'VAT' => '0.2000', 'Long Product Name' => $damaged]),
    ];

    $report = importRows($make);
    $product = VendorSync::product('FS', '900001');
    $offer = DB::table('vendor_offers')->where('vendor_sku', 'G-1')->first();

    expect($product->translateAttribute('title'))->toBe("FS96 Women's Safety Court Shoe")
        ->and($product->translateAttribute('description'))->toBe("Women's shoe, 100% leather")
        ->and($offer->vendor_product_name)->toBe("FS96 Women's Safety Court Shoe")
        ->and(collect($report->messages)->filter(fn ($m): bool => str_contains($m, 'damaged characters')))->toHaveCount(1)
        ->and($report->messages[0])->toContain('damaged characters repaired in Long Product Name, InspirationalCopy')
        ->and($report->tallies['damaged_text'])->toHaveKeys(['Long Product Name', 'InspirationalCopy']);

    $again = importRows($make);

    expect($again->unchanged)->toBe(1)
        ->and($again->updated)->toBe(0);
});

it('reports rows with an unusable VAT rate in one line', function (): void {
    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'H-1', 'UK Size' => '3', 'EU Size' => '36', 'VAT' => '0.2000']),
        fsRow($t, ['Product Reference' => 'H-2', 'UK Size' => '4', 'EU Size' => '37', 'VAT' => '0.1500']),
        fsRow($t, ['Product Reference' => 'H-3', 'UK Size' => '5', 'EU Size' => '38', 'VAT' => 'abc']),
    ]);

    expect(ProductVariant::query()->pluck('sku')->all())->toBe(['FS-H-1'])
        ->and(collect($report->messages)->filter(fn ($m): bool => str_contains($m, 'VAT rate that is not')))->toHaveCount(1)
        ->and($report->messages[0])->toContain("2 SKUs with a VAT rate that is not 0 or 20% (VAT '0.1500', VAT 'abc'); not imported");
});

it('reports rows with no colour in one line', function (): void {
    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'I-1', 'UK Size' => '3', 'EU Size' => '36', 'Colour Code' => 'N/A', 'Colour Extention' => 'N/A']),
        fsRow($t, ['Product Reference' => 'I-2', 'UK Size' => '4', 'EU Size' => '37', 'Colour Code' => 'N/A', 'Colour Extention' => '']),
    ]);

    expect(VendorProduct::query()->sole()->draft_reasons)->toContain(DraftReason::NO_COLOUR)
        ->and($report->messages[0])->toBe('Mirak Test Plimsoll [900001]: 2 SKUs with no colour; product drafted (SKUs: I-1, I-2)');
});

it('drafts a product whose rows all have a cost at or above the RRP, in one line', function (): void {
    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'J-1', 'UK Size' => '3', 'EU Size' => '36', 'VAT' => '0.2000', 'Trade Price' => '14.00', 'SRP' => '6.25']),
        fsRow($t, ['Product Reference' => 'J-2', 'UK Size' => '4', 'EU Size' => '37', 'VAT' => '0.2000', 'Trade Price' => '14.00', 'SRP' => '6.25']),
    ]);

    expect(VendorProduct::query()->sole()->draft_reasons)->toContain(DraftReason::COST_AT_OR_ABOVE_RRP)
        ->and($report->messages)->toContain('Mirak Test Plimsoll [900001]: 2 SKUs with cost at or above RRP; stock set to 0 (SKUs: J-1, J-2)')
        ->and(VendorSync::product('FS', '900001')->status)->toBe('draft')
        ->and(VendorSync::product('FS', '900001')->variants->sum('stock'))->toBe(0);
});

it('maps every Group Class seen in the real feed to its collection, and still drafts an unknown one', function (): void {
    // Childrens Wellingtons and Boys BTS are children's Group Classes: out of
    // scope entirely, checked separately below, not in this collection map.
    $classes = [
        'Mule Ladies Slippers' => 'slippers', 'Mens Mule Slippers' => 'slippers', 'Touch Fastening Mens Slippers' => 'slippers',
        'Trainers Safety' => 'safety-trainers', 'Ladies Safety Shoes' => 'safety-shoes', 'Waterproof Safety Footwear' => 'safety-boots',
        // A non-safety Group Class, but genuinely uncertified: no override.
        'Mens Classic Lace Shoes' => 'casual-footwear',
        'Plain PVC Wellingtons' => 'wellingtons', 'Plain Rubber Wellingtons' => 'wellingtons', 'Waders' => 'wellingtons',
        'Canvas Ladies Summer' => 'sandals-summer-shoes', 'Garden Shoes' => 'wellingtons',
        'A Class Nobody Mapped' => null,
    ];

    $childrensClasses = ['Childrens Wellingtons', 'Boys BTS'];

    // The three genuinely safety Group Classes need a real EN ISO 20345
    // rating, or the safety-rating check would move them to casual-footwear
    // (the template default is "Non Safety").
    $safetyRated = ['Trainers Safety', 'Ladies Safety Shoes', 'Waterproof Safety Footwear'];

    $report = importRows(function (array $t) use ($classes, $childrensClasses, $safetyRated): array {
        $rows = [];
        $n = 0;

        foreach ([...array_keys($classes), ...$childrensClasses] as $class) {
            $n++;
            $rows[] = fsRow($t, [
                'ProductID' => (string) (910000 + $n), 'Family Product Reference' => (910000 + $n).'-1',
                'Product Reference' => "K-{$n}", 'Short Product Name' => "Class {$n}", 'Group Class' => $class,
                'UK Size' => '5', 'EU Size' => '38', 'VAT' => '0.2000',
                ...(in_array($class, $safetyRated, true) ? ['Safety Standard' => 'EN ISO 20345:2011', 'Safety Cat' => 'SRC', 'Safety' => 'S3'] : []),
            ]);
        }

        return $rows;
    });

    $n = 0;

    foreach ($classes as $class => $handle) {
        $n++;
        $product = VendorSync::product('FS', (string) (910000 + $n));

        expect($product->collections->pluck('id')->all())->toBe($handle === null ? [] : [CatalogueKeys::collection($handle)->id], $class);
    }

    $unmappedIndex = array_search(null, array_values($classes), true) + 1;

    expect($report->errors)->toBe(0)
        ->and(VendorProduct::query()->where('vendor_product_id', (string) (910000 + $unmappedIndex))->sole()->draft_reasons)->toContain(DraftReason::UNMAPPED_CATEGORY)
        ->and($report->tallies['draft_reason'][DraftReason::UNMAPPED_CATEGORY])->toBe(1)
        // The children's Group Classes were never created at all.
        ->and($report->tallies['out_of_scope']['children'])->toBe(count($childrensClasses))
        ->and(VendorProduct::query()->whereIn('vendor_product_id', [(string) (910000 + count($classes) + 1), (string) (910000 + count($classes) + 2)])->count())->toBe(0);
});

it('excludes children\'s, boys\' and girls\' lines by Group Class or by name, keeping small adult sizes', function (): void {
    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'Q-1', 'ProductID' => '920001', 'Family Product Reference' => '920001-1', 'Group Class' => 'Boys Boots', 'Short Product Name' => 'Boys Boots', 'UK Size' => '2', 'EU Size' => '35']),
        fsRow($t, ['Product Reference' => 'Q-2', 'ProductID' => '920002', 'Family Product Reference' => '920002-1', 'Group Class' => 'Girls BTS', 'Short Product Name' => 'Girls Shoe', 'UK Size' => '2', 'EU Size' => '35']),
        // Not a children's Group Class, but the name says Junior.
        fsRow($t, ['Product Reference' => 'Q-3', 'ProductID' => '920003', 'Family Product Reference' => '920003-1', 'Group Class' => 'Plain PVC Wellingtons', 'Short Product Name' => 'DU SPORT JNR', 'Long Product Name' => 'Sport Wellington Junior', 'UK Size' => '2', 'EU Size' => '35']),
        // A small adult size in an ordinary Group Class with no children's wording: kept.
        fsRow($t, ['Product Reference' => 'Q-4', 'ProductID' => '920004', 'Family Product Reference' => '920004-1', 'Group Class' => 'Unisex Sports', 'Short Product Name' => 'Consul Lace Trainer', 'UK Size' => '1', 'EU Size' => '33']),
    ]);

    expect($report->outOfScope)->toBe(3)
        ->and($report->tallies['out_of_scope'])->toBe(['children' => 3])
        ->and(VendorProduct::query()->whereIn('vendor_product_id', ['920001', '920002', '920003'])->count())->toBe(0)
        ->and(VendorSync::product('FS', '920004')->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('sports-trainers')->id]);
});

it('moves a certified product out of a non-safety Group Class, and an uncertified one out of a safety Group Class', function (): void {
    $report = importRows(fn (array $t): array => [
        // A safety-rated wellington filed under the non-safety Group Class.
        fsRow($t, [
            'Product Reference' => 'R-1', 'ProductID' => '930001', 'Family Product Reference' => '930001-1',
            'Group Class' => 'Plain Rubber Wellingtons', 'Short Product Name' => 'Rated Wellington',
            'Safety Standard' => 'EN ISO 20345:2011', 'Safety Cat' => 'SRC', 'Safety' => 'S5',
            'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000',
        ]),
        // An occupational-rated boot filed under the non-safety Group Class.
        fsRow($t, [
            'Product Reference' => 'R-2', 'ProductID' => '930002', 'Family Product Reference' => '930002-1',
            'Group Class' => 'Mens Boots', 'Short Product Name' => 'Rated Occupational Boot',
            'Safety Standard' => 'EN ISO 20347:2012', 'Safety Cat' => 'SRA', 'Safety' => 'OB',
            'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000',
        ]),
        // An uncertified shoe filed under a safety Group Class.
        fsRow($t, [
            'Product Reference' => 'R-3', 'ProductID' => '930003', 'Family Product Reference' => '930003-1',
            'Group Class' => 'Shoes- Safety', 'Short Product Name' => 'Not Actually Certified',
            'Safety Standard' => 'Not Set', 'Safety Cat' => '', 'Safety' => '',
            'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000',
        ]),
    ]);

    expect($report->errors)->toBe(0)
        ->and(VendorSync::product('FS', '930001')->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('safety-wellingtons')->id])
        ->and(VendorSync::product('FS', '930002')->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('occupational-footwear')->id])
        ->and(VendorSync::product('FS', '930003')->collections->pluck('id')->all())->toBe([CatalogueKeys::collection('casual-footwear')->id]);
});

it('maps France, keeps WRU and adds WR for waterproof rows', function (): void {
    $report = importRows(fn (array $t): array => [
        fsRow($t, [
            'Product Reference' => 'L-1', 'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000',
            'Country Of Origin' => 'France', 'Safety Standard' => 'EN ISO 20347:2012', 'Safety Cat' => 'E FO SRA WRU', 'Safety' => 'OB',
            'Waterproof' => '1', 'Group Class' => 'Mens Occupational Footwear',
        ]),
    ]);

    $product = VendorSync::product('FS', '900001');

    expect($product->attr('country_of_origin'))->toBe('FR')
        ->and($product->attr('features'))->toBe(['E', 'FO', 'SRA', 'WRU', 'WR'])
        ->and($report->tallies)->not->toHaveKey('unknown_country')
        ->and($report->tallies)->not->toHaveKey('unknown_feature');
});

it('publishes a product when one colour has no images, using the images of the other', function (): void {
    importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'M-1', 'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000', 'Family Product Reference' => '900001-1']),
        fsRow($t, [
            'Product Reference' => 'M-2', 'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000', 'Family Product Reference' => '900001-2',
            'Colour Code' => 'BLACK', 'Colour Extention' => 'Black', 'Image1' => '',
        ]),
    ]);

    VendorSync::syncImages(['900001']);

    $product = VendorSync::product('FS', '900001');
    $white = $product->variants->first(fn (ProductVariant $v): bool => $v->sku === 'FS-M-1');
    $black = $product->variants->first(fn (ProductVariant $v): bool => $v->sku === 'FS-M-2');

    expect($product->status)->toBe('published')
        ->and($product->getMedia('images'))->toHaveCount(1)
        ->and($white->images)->toHaveCount(1)
        ->and($black->images)->toHaveCount(0);
});

it('downloads Footsure images over https first and falls back to http', function (): void {
    importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'N-1', 'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000']),
    ]);

    // The https address fails, the http address works.
    VendorSync::fakeHttp(brokenImages: ['https://ts-images']);

    $result = app(ProductImageSync::class)->sync(VendorProduct::query()->sole());

    expect($result->stored)->toBe(1)
        ->and($result->failed)->toBe([]);

    $urls = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->filter(fn (string $url): bool => str_contains($url, 'ts-images'))->values()->all();

    // https is tried first (and retried), http only once it has failed.
    expect($urls[0])->toBe('https://ts-images.gardinerbros.co.uk/test/one.jpg')
        ->and(end($urls))->toBe('http://ts-images.gardinerbros.co.uk/test/one.jpg')
        ->and(count(array_filter($urls, fn (string $url): bool => str_starts_with($url, 'http://'))))->toBe(1)
        ->and(array_search('http://ts-images.gardinerbros.co.uk/test/one.jpg', $urls, true))->toBe(count($urls) - 1);
});

it('fails loudly when every Footsure image download fails', function (): void {
    importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'O-1', 'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000']),
    ]);

    VendorSync::fakeHttp(brokenImages: ['ts-images']);

    $link = VendorProduct::query()->sole();

    expect(fn () => (new SyncProductImages($link->id))->handle(app(ProductImageSync::class)))
        ->toThrow(ImageSyncFailed::class, 'Image sync failed for vendor product 900001: none of the 1 images could be saved');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://ts-images'));
});

it('prints one progress line per product, using the vendor\'s own product name', function (): void {
    VendorSync::footsureFile(fn (array $rows): array => [
        fsRow($rows[0], ['Product Reference' => 'P-1', 'ProductID' => '990001', 'Family Product Reference' => '990001-1', 'Short Product Name' => 'Ligero', 'UK Size' => '5', 'EU Size' => '38', 'VAT' => '0.2000']),
        fsRow($rows[0], ['Product Reference' => 'P-2', 'ProductID' => '990002', 'Family Product Reference' => '990002-1', 'Short Product Name' => 'Tactic', 'UK Size' => '6', 'EU Size' => '39', 'VAT' => '0.2000']),
    ]);

    $this->artisan('vendorsync:catalogue', ['vendor' => 'FS'])
        ->expectsOutputToContain('Ligero (1 of 2)')
        ->expectsOutputToContain('Tactic (2 of 2)')
        ->assertSuccessful();
});

it('does not count a product made only of placeholder rows as an error', function (): void {
    $placeholder = ['Weight' => '999', 'Length' => '333', 'Width' => '222', 'Height' => '111', 'EU Size' => '', 'Barcode' => '', 'Stock' => '0'];

    $report = importRows(fn (array $t): array => [
        fsRow($t, ['Product Reference' => 'P-1', 'UK Size' => '7', 'VAT' => '0.2000', ...$placeholder]),
        fsRow($t, ['Product Reference' => 'P-2', 'UK Size' => '10', 'VAT' => '0.2000', ...$placeholder]),
        fsRow($t, [
            'ProductID' => '900002', 'Family Product Reference' => '900002-1', 'Product Reference' => 'P-3',
            'Short Product Name' => 'Real One', 'UK Size' => '8', 'EU Size' => '42', 'VAT' => '0.2000',
        ]),
    ]);

    expect($report->errors)->toBe(0)
        ->and($report->outOfScope)->toBe(1)
        ->and($report->tallies['out_of_scope'])->toBe(['placeholder_rows_only' => 1])
        ->and($report->created)->toBe(1)
        ->and(VendorProduct::query()->pluck('vendor_product_id')->all())->toBe(['900002'])
        ->and($report->messages[0])->toBe('Mirak Test Plimsoll [900001]: 2 placeholder rows (no barcode, no EU size, placeholder measurements); not imported (SKUs: P-1, P-2)');
});
