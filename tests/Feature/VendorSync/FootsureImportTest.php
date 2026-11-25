<?php

declare(strict_types=1);

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use App\Domain\VendorSync\Import\DraftReason;
use App\Domain\VendorSync\Import\FieldLocks;
use App\Domain\VendorSync\Import\RunRecorder;
use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Domain\VendorSync\Notifications\SyncRunAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Tests\Support\CatalogueFactory;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
    VendorSync::fakeHttp();
    Notification::fake();

    // The fixture export was created on 11/11/2026 at 06:05.
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));
});

function fsVariant(string $sku): ProductVariant
{
    return ProductVariant::query()->where('sku', $sku)->sole();
}

function taxHandle(ProductVariant $variant): ?string
{
    return CatalogueKeys::handleFor(KeyType::TaxClass, $variant->taxClass);
}

/**
 * @return array<string, array<string, string>>
 */
function catalogueTimestamps(): array
{
    $tables = ['vendor_products', 'vendor_offers', (new Product)->getTable(), (new ProductVariant)->getTable(), (new ProductVariant)->prices()->getRelated()->getTable()];

    return collect($tables)->mapWithKeys(fn (string $table): array => [
        $table => DB::table($table)->pluck('updated_at', 'id')->map(fn ($value): string => (string) $value)->all(),
    ])->all();
}

it('imports one product per ProductID with a colour per family', function (): void {
    VendorSync::footsureFile();

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('succeeded')
        ->and($report->seen)->toBe(32)
        ->and($report->created)->toBe(32)
        ->and($report->outOfScope)->toBe(0)
        ->and(VendorProduct::query()->count())->toBe(32);

    // Audrey Slipper: ten families are ten colours of one product.
    $audrey = VendorSync::product('FS', '66');

    expect($audrey->translateAttribute('name'))->toBe('Audrey Slipper')
        ->and($audrey->defaultUrl->slug)->toBe('gbs-audrey-slipper')
        ->and($audrey->variants->flatMap->values->where('option.handle', 'colour')->map->translate('name')->unique()->count())->toBe(10)
        ->and($audrey->collections->pluck('id')->all())->toContain(CatalogueKeys::collection('casual-footwear')->id)
        // Clearance on any colour puts the product in the clearance range.
        ->and($audrey->collections->pluck('id')->all())->toContain(CatalogueKeys::collection('clearance')->id);
});

it('writes classes, tax classes, prices and vendor rows in contract shape', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $fs9 = VendorSync::product('FS', '1049');
    $fs65 = VendorSync::product('FS', '1055');
    $fs30c = fsVariant('FS-00345-00772-03');

    expect($fs9->attr('safety_class'))->toBe('SBP')
        ->and($fs9->attr('features'))->toBe(['P', 'SRA'])
        ->and($fs65->attr('safety_class'))->toBe('S1P')
        ->and(taxHandle($fs30c))->toBe('zero-protective')
        ->and(taxHandle(fsVariant('FS-01049-00848-03')))->toBe('zero-protective')
        ->and(taxHandle(fsVariant('FS-01055-00821-03')))->toBe('standard');

    // FS30C: cost 26.99 x 1.5 = 40.49, RRP 41.99 (zero rated, so no VAT to remove).
    $price = $fs30c->prices->sole();
    $offer = VendorOffer::query()->where('product_variant_id', $fs30c->id)->sole();

    expect($price->getRawOriginal('price'))->toBe(4049)
        ->and($price->getRawOriginal('compare_price'))->toBe(4199)
        ->and($offer->vendor_sku)->toBe('00345-00772-03')
        ->and($offer->vendor_product_name)->toBe('FS30C Lace-up Safety Boot')
        ->and($offer->vendor_colour)->toBe('Black')
        ->and($offer->cost_price)->toBe(2699)
        ->and($offer->ean)->toBe('5038601016828')
        // Footsure keeps a safety buffer of one pair.
        ->and($offer->stock)->toBe(21)
        ->and($fs30c->stock)->toBe(20)
        ->and((float) $fs30c->weight_value)->toBe(1.65)
        ->and($fs30c->attr('eu_size'))->toBe('38');
});

it('gives children\'s plimsolls the zero-childrens class and names sizes by scale', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $sizes = fn (string $id): array => VendorSync::product('FS', $id)->variants
        ->mapWithKeys(fn (ProductVariant $variant): array => [
            $variant->values->firstWhere('option.handle', 'shoe_size')->translate('name') => (float) $variant->values->firstWhere('option.handle', 'shoe_size')->meta['sort'],
        ])->sort()->all();

    // Product 320: UK 10 to 13 are EU 28 to 32 (infant scale), UK 2 is EU 35 (junior).
    expect(taxHandle(VendorSync::product('FS', '570')->variants->first()))->toBe('zero-childrens')
        ->and(array_map(strval(...), array_keys($sizes('320'))))->toBe(['Kids 10', 'Kids 11', 'Kids 12', 'Kids 13', '2'])
        // Product 570: UK 1 is EU 33 and UK 2 is EU 34, so plain sizes even though zero rated.
        ->and(array_map(strval(...), array_keys($sizes('570'))))->toContain('1')->not->toContain('Kids 1')->not->toContain('Kids 2')
        // Sorted by EU size: infant sizes before 1, before 2.
        ->and($sizes('320')['Kids 13'])->toBeLessThan($sizes('320')['2'])
        ->and(VendorSync::product('FS', '570')->collections->pluck('id')->all())->toContain(CatalogueKeys::collection('casual-footwear')->id)
        // The placeholder brand is never customer-facing.
        ->and(VendorSync::product('FS', '570')->brand_id)->toBeNull();
});

it('maps every Group Class in the fixture, so only rule drafts remain', function (): void {
    VendorSync::footsureFile();
    $report = VendorSync::import('FS');

    expect($report->tallies['draft_reason'] ?? [])->not->toHaveKey(DraftReason::UNMAPPED_CATEGORY);

    // The discontinued Amblers boot is draft with stock 0.
    $chelmsford = VendorSync::product('FS', '3717');

    expect($chelmsford->status)->toBe('draft')
        ->and($chelmsford->variants->sum('stock'))->toBe(0)
        // Group Class "Mens Boots".
        ->and($chelmsford->collections->pluck('id')->all())->toContain(CatalogueKeys::collection('boots')->id);

    // Dasco insoles: cost above RRP, so stock 0, and reported.
    expect(VendorSync::product('FS', '1615')->variants->sum('stock'))->toBe(0)
        ->and(collect($report->messages)->contains(fn (string $message): bool => str_contains($message, 'Dasco Bama Warm Felta 5 Pack [1615]: 1 SKU with cost at or above RRP; stock set to 0 (SKUs: 01615-05821-04)')))->toBeTrue();
});

it('skips the placeholder rows beside the real ones', function (): void {
    VendorSync::footsureFile();
    $report = VendorSync::import('FS');

    expect(ProductVariant::query()->where('sku', 'FS-00568-01277-07')->exists())->toBeTrue()
        ->and(ProductVariant::query()->where('sku', 'FS-00568-01277-12')->exists())->toBeFalse()
        // The three placeholder rows of product 568 are one line in the report.
        ->and(collect($report->messages)->filter(fn (string $message): bool => str_contains($message, '[568]: 3 placeholder rows')))->toHaveCount(1);
});

it('keeps products draft until they have an image, then publishes them', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    expect(VendorSync::product('FS', '345')->status)->toBe('draft');

    VendorSync::syncImages(['345']);

    $product = VendorSync::product('FS', '345');
    $media = $product->getMedia('images');

    expect($product->status)->toBe('published')
        ->and($media)->toHaveCount(4)
        ->and($media->first()->getCustomProperty('role'))->toBe('main')
        ->and($media->first()->getCustomProperty('colour'))->toBe('Black')
        ->and($media->first()->getCustomProperty('primary'))->toBeTrue()
        ->and($media->last()->getCustomProperty('role'))->toBe('detail')
        ->and($media->first()->disk)->toBe('products')
        ->and($product->variants->first()->images)->toHaveCount(4)
        ->and((bool) $product->variants->first()->images->first()->pivot->primary)->toBeTrue();
});

it('puts the main images of Footsure safety shoes and boots on the baseline, and no others', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');
    VendorSync::syncImages(['345']);

    $product = VendorSync::product('FS', '345');
    $inList = $product->collections->pluck('id')->intersect([
        CatalogueKeys::collection('safety-shoes')->id,
        CatalogueKeys::collection('safety-boots')->id,
    ])->isNotEmpty();

    $markers = fn () => $product->refresh()->getMedia('images')->mapWithKeys(fn ($image): array => [$image->id => [$image->getCustomProperty('role'), $image->getCustomProperty('optimised')]])->values()->all();

    foreach ($markers() as [$role, $marker]) {
        // A detail picture is never aligned; a main picture is when the product is a safety shoe or boot.
        expect($marker)->toBe($role === 'main' && $inList ? 'webp-1200-q85-base11-side4-ar100' : 'webp-1200-q85');
    }

    // Another category list changes only the main pictures: with every category, this product's main picture is aligned.
    config(['vendorsync.images.align.collections' => []]);
    VendorSync::syncImages(['345']);

    foreach ($markers() as [$role, $marker]) {
        expect($marker)->toBe($role === 'main' ? 'webp-1200-q85-base11-side4-ar100' : 'webp-1200-q85');
    }
});

it('changes no rows when the same file is imported twice', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $before = catalogueTimestamps();

    Carbon::setTestNow(now()->addHour());
    VendorSync::footsureFile();
    $report = VendorSync::import('FS');

    expect($report->status)->toBe('succeeded')
        ->and($report->unchanged)->toBe(32)
        ->and($report->created + $report->updated)->toBe(0)
        ->and(catalogueTimestamps())->toBe($before);
});

it('archives the processed file', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    expect(File::glob(VendorSync::path('incoming').'/*.csv'))->toBe([])
        ->and(File::glob(VendorSync::path('archive').'/footsure-*.csv'))->toHaveCount(1);
});

it('aborts without writing when a column is renamed', function (): void {
    $header = str_replace('Trade Price', 'TradePrice', (string) fgets(fopen(VendorSync::fixture('Footsure/footsure.csv'), 'r')));
    VendorSync::footsureFile(header: trim($header));

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('aborted')
        ->and($report->messages[0])->toContain('Trade Price')
        ->and(Product::query()->count())->toBe(0)
        ->and(SyncRun::query()->latest('id')->first()->status)->toBe('aborted');

    Notification::assertSentOnDemand(SyncRunAlert::class);
});

it('aborts without writing when the file is stale', function (): void {
    VendorSync::footsureFile();
    Carbon::setTestNow(Carbon::parse('2026-11-12 18:06:00', 'Europe/London'));

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('aborted')
        ->and($report->messages[0])->toContain('Stale Footsure feed')
        ->and(Product::query()->count())->toBe(0);

    Notification::assertSentOnDemand(SyncRunAlert::class);
});

it('aborts without writing when half of the products are missing', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');
    $before = catalogueTimestamps();

    VendorSync::footsureFile(function (array $rows): array {
        $ids = array_slice(array_values(array_unique(array_column($rows, 'ProductID'))), 0, 16);

        return array_values(array_filter($rows, fn (array $row): bool => in_array($row['ProductID'], $ids, true)));
    });

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('aborted')
        ->and($report->messages[0])->toContain('Safety threshold')
        ->and(catalogueTimestamps())->toBe($before);
});

it('drafts a removed product after the second consecutive run, never the first, and never deletes it', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');
    VendorSync::syncImages(['48']);

    $withoutEva = fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => $row['ProductID'] !== '48'));
    $eva = VendorSync::product('FS', '48');

    expect($eva->status)->toBe('published');

    VendorSync::footsureFile($withoutEva);
    VendorSync::import('FS');

    expect($eva->refresh()->status)->toBe('published')
        ->and(VendorProduct::query()->where('vendor_product_id', '48')->sole()->missed_runs)->toBe(1);

    VendorSync::footsureFile($withoutEva);
    $report = VendorSync::import('FS');

    expect($eva->refresh()->status)->toBe('draft')
        ->and($eva->variants()->sum('stock'))->toBe(0)
        ->and($eva->variants()->count())->toBe(7)
        ->and($report->drafted)->toBe(1);

    // Back in the feed: on sale again.
    VendorSync::footsureFile();
    VendorSync::import('FS');

    expect($eva->refresh()->status)->toBe('published')
        ->and($eva->variants()->sum('stock'))->toBeGreaterThan(0);
});

it('never overwrites a locked description but updates an unlocked one', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $locked = VendorSync::product('FS', '48');
    $open = VendorSync::product('FS', '90');
    $original = $locked->translateAttribute('description');

    app(FieldLocks::class)->lock($locked->id, 'description');

    VendorSync::footsureFile(fn (array $rows): array => array_map(function (array $row): array {
        if (in_array($row['ProductID'], ['48', '90'], true)) {
            $row['InspirationalCopy'] = 'New vendor description.';
        }

        return $row;
    }, $rows));

    $report = VendorSync::import('FS');

    expect($report->updated)->toBe(2)
        ->and($locked->refresh()->translateAttribute('description'))->toBe($original)
        ->and($open->refresh()->translateAttribute('description'))->toBe('New vendor description.');
});

it('keeps an admin edit to an unlocked content field until the vendor changes it', function (): void {
    VendorSync::footsureFile();
    VendorSync::import('FS');

    $product = VendorSync::product('FS', '90');
    $data = $product->attribute_data;
    $data['title'] = new TranslatedText(['en' => new Text('Edited in the admin panel')]);
    $product->update(['attribute_data' => $data]);

    // A stock change makes the payload differ, but not the title.
    VendorSync::footsureFile(fn (array $rows): array => array_map(function (array $row): array {
        if ($row['ProductID'] === '90') {
            $row['Stock'] = '50';
        }

        return $row;
    }, $rows));

    VendorSync::import('FS');

    expect($product->refresh()->translateAttribute('title'))->toBe('Edited in the admin panel')
        ->and($product->variants()->max('stock'))->toBe(49);
});

it('skips excluded brands and a Footsure variant whose EAN belongs to another vendor', function (): void {
    $other = app(RunRecorder::class)->vendor('SJ')->id;
    $existing = CatalogueFactory::product('Elsewhere', [['colour' => 'Black', 'size' => '5', 'stock' => 1]]);

    DB::table('vendor_offers')->insert([
        'product_variant_id' => $existing->variants->first()->id, 'vendor_id' => $other, 'vendor_sku' => '1',
        'vendor_product_name' => 'ELSEWHERE', 'ean' => '5038601016828', 'stock' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    VendorSync::footsureFile(fn (array $rows): array => array_map(function (array $row): array {
        if ($row['ProductID'] === '252') {
            $row['Brand'] = 'Safety Jogger Works';
        }

        return $row;
    }, $rows));

    $report = VendorSync::import('FS');

    expect($report->outOfScope)->toBe(1)
        ->and(VendorProduct::query()->where('vendor_product_id', '252')->exists())->toBeFalse()
        ->and(ProductVariant::query()->where('sku', 'FS-00345-00772-03')->exists())->toBeFalse()
        ->and(ProductVariant::query()->where('sku', 'FS-00345-00772-04')->exists())->toBeTrue()
        ->and(collect($report->messages)->contains(fn (string $message): bool => str_contains($message, 'already belongs to another vendor')))->toBeTrue();
});

it('adds WR for waterproof rows and sets a discontinued colour to stock 0', function (): void {
    VendorSync::footsureFile(fn (array $rows): array => array_map(function (array $row): array {
        if ($row['ProductID'] === '345') {
            $row['Waterproof'] = '1';
        }

        if ($row['Family Product Reference'] === '52-00095') {
            $row['Colour Status'] = 'No More';
        }

        return $row;
    }, $rows));

    VendorSync::import('FS');

    $exeter = VendorSync::product('FS', '52');
    $stockByColour = $exeter->variants->groupBy(fn (ProductVariant $variant): string => $variant->values->firstWhere('option.handle', 'colour')->translate('name'))
        ->map(fn ($variants): int => (int) $variants->sum('stock'));

    expect(VendorSync::product('FS', '345')->attr('features'))->toBe(['SR', 'WR'])
        ->and($stockByColour['Brown'])->toBe(0)
        ->and($stockByColour['Grey'])->toBeGreaterThan(0);
});

it('writes nothing on a dry run and saves a report', function (): void {
    VendorSync::footsureFile();

    $report = VendorSync::import('FS', dryRun: true);

    expect($report->status)->toBe('succeeded')
        ->and($report->created)->toBe(32)
        ->and($report->samples)->not->toBeEmpty()
        ->and(Product::query()->count())->toBe(0)
        ->and(SyncRun::query()->count())->toBe(0)
        ->and(File::glob(VendorSync::path('incoming').'/*.csv'))->toHaveCount(1)
        ->and(File::glob(VendorSync::path('reports').'/fs-catalogue-dry-run-*.json'))->toHaveCount(1);
});
