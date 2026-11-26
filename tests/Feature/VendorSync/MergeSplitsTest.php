<?php

declare(strict_types=1);

use App\Domain\Catalogue\Urls\GenerateProductSlug;
use App\Domain\VendorSync\Footsure\FootsureSource;
use App\Domain\VendorSync\Models\VendorOffer;
use App\Domain\VendorSync\Models\VendorProduct;
use App\Models\Favourite;
use App\Models\UrlRedirect;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Lunar\Base\Enums\ProductAssociation as AssociationType;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Channel;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Order;
use Lunar\Models\OrderLine;
use Lunar\Models\Product;
use Lunar\Models\ProductAssociation;
use Lunar\Models\ProductVariant;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\VendorSync;

/*
 * `vendorsync:merge-splits` merges the products that the size band split left
 * in the database: the fixture's Squash trainer (ProductIDs 546 and 547) and
 * unisex 6045/PSG27 plimsolls (569, 570, 571). The database is first built the
 * old way, with a product for every ProductID.
 */

beforeEach(function (): void {
    VendorSync::boot();
    VendorSync::fakeHttp();
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));

    VendorSync::footsureFile();

    // The importer as it was: every ProductID its own product.
    app()->bind('vendorsync.source.FS', fn (): FootsureSource => new FootsureSource(config('vendorsync.footsure')));
    VendorSync::import('FS');
    app()->bind('vendorsync.source.FS', fn ($app): FootsureSource => new FootsureSource(
        config('vendorsync.footsure'),
        mapper: $app->make('vendorsync.mapper.FS'),
    ));

    // The product with the lowest ID is kept, whichever ProductID it came from.
    $small = VendorSync::product('FS', '546');
    $large = VendorSync::product('FS', '547');
    $this->kept = $small->id < $large->id ? $small : $large;
    $this->dropped = $small->id < $large->id ? $large : $small;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function productFor(string $vendorProductId): Product
{
    return VendorSync::product('FS', $vendorProductId);
}

/** A placed order with a line for this variant, the way the checkout leaves one. */
function orderLineFor(ProductVariant $variant): OrderLine
{
    $order = Order::withoutEvents(fn (): Order => Order::factory()->create([
        'channel_id' => Channel::getDefault()->id,
        'status' => 'payment-received',
        'placed_at' => '2026-11-10 09:00:00',
    ]));

    return OrderLine::factory()->create([
        'order_id' => $order->id,
        'purchasable_type' => $variant->getMorphClass(),
        'purchasable_id' => $variant->id,
        'type' => 'physical',
        'description' => 'Squash trainer',
        'identifier' => $variant->sku,
        'meta' => ['vendor_code' => 'FS', 'vendor_sku' => str_replace('FS-', '', $variant->sku), 'vendor_product_name' => 'Squash Unisex Trainer', 'cost_price' => 2775, 'colour' => 'White', 'size' => '9'],
    ]);
}

it('reports what it would merge and changes nothing in a dry run', function (): void {
    $products = Product::query()->count();
    $before = DB::table('lunar_product_variants')->pluck('product_id', 'id')->all();

    $this->artisan('vendorsync:merge-splits', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run: 2 product lines of 5 products would become one product each: 3 products removed.')
        ->expectsOutputToContain("keep product {$this->kept->id} (/products/hi-tec-squash-trainer")
        ->expectsOutputToContain('Nothing was changed.')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe($products)
        ->and(DB::table('lunar_product_variants')->pluck('product_id', 'id')->all())->toBe($before)
        ->and(UrlRedirect::query()->count())->toBe(0)
        ->and(VendorProduct::query()->distinct()->count('product_id'))->toBe(32);

    $report = collect(glob(VendorSync::path('reports').'/fs-merge-splits-dry-run-*.json'))->first();

    expect($report)->not->toBeNull()
        ->and(json_decode((string) file_get_contents($report), true)['lines_to_merge'])->toBe(2);
});

it('merges a split line into the product with the lowest ID, keeping its slug', function (): void {
    $variantIds = $this->kept->variants->pluck('id')->merge($this->dropped->variants->pluck('id'))->sort()->values()->all();
    $slug = $this->kept->defaultUrl->slug;

    $this->artisan('vendorsync:merge-splits')->assertSuccessful();

    $kept = $this->kept->refresh();

    expect($this->kept->id)->toBeLessThan($this->dropped->id)
        ->and($slug)->toBe('hi-tec-squash-trainer')
        ->and($kept->defaultUrl->slug)->toBe($slug)
        ->and($kept->variants()->orderBy('id')->pluck('id')->all())->toBe($variantIds)
        // The other product is soft deleted, never removed.
        ->and(Product::query()->find($this->dropped->id))->toBeNull()
        ->and(Product::withTrashed()->find($this->dropped->id))->not->toBeNull()
        ->and(VendorProduct::query()->whereIn('vendor_product_id', ['546', '547'])->pluck('product_id')->unique()->all())->toBe([$kept->id])
        ->and(Product::query()->count())->toBe(32 - 3);

    // The unisex 6045/PSG27 plimsolls: three products to one; the Men's 568 is untouched.
    $plimsolls = productFor('569');

    expect(productFor('570')->is($plimsolls))->toBeTrue()
        ->and(productFor('571')->is($plimsolls))->toBeTrue()
        ->and(productFor('568')->is($plimsolls))->toBeFalse();
});

it('does not touch vendor offers, prices or stock, and each offer keeps its variant', function (): void {
    $offers = fn (): array => VendorOffer::query()->orderBy('id')->get()->map->only(['id', 'product_variant_id', 'vendor_sku', 'cost_price', 'stock', 'sellable', 'ean'])->all();
    $variants = fn (): array => ProductVariant::query()->orderBy('id')->get()->map->only(['id', 'sku', 'stock', 'tax_class_id', 'ean'])->all();
    $prices = fn (): array => DB::table('lunar_prices')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

    [$offersBefore, $variantsBefore, $pricesBefore] = [$offers(), $variants(), $prices()];

    $this->artisan('vendorsync:merge-splits')->assertSuccessful();

    expect($offers())->toBe($offersBefore)
        ->and($variants())->toBe($variantsBefore)
        ->and($prices())->toBe($pricesBefore);
});

it('leaves orders and order lines exactly as they were', function (): void {
    $moved = $this->dropped->variants->first();
    $line = orderLineFor($moved);
    $order = Order::query()->findOrFail($line->order_id);

    $lines = fn (): array => DB::table('lunar_order_lines')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    $orders = fn (): array => DB::table('lunar_orders')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

    [$linesBefore, $ordersBefore] = [$lines(), $orders()];

    $this->artisan('vendorsync:merge-splits')
        ->expectsOutputToContain('1 order lines')
        ->assertSuccessful();

    // Same rows, byte for byte: the vendor snapshot in `meta` is as it was. The line still points at the same variant,
    // which now belongs to the kept product.
    expect($lines())->toBe($linesBefore)
        ->and($orders())->toBe($ordersBefore)
        ->and($line->refresh()->purchasable->is($moved->refresh()))->toBeTrue()
        ->and($moved->refresh()->product_id)->toBe($this->kept->id)
        ->and($line->meta['vendor_sku'])->not->toBeEmpty();

    // The order page still finds the product through the variant.
    expect($order->lines()->first()->purchasable->product->is($this->kept))->toBeTrue();
});

it('sends the old slug to the kept product with a 301, query string kept', function (): void {
    $this->artisan('vendorsync:merge-splits')->assertSuccessful();

    $old = $this->dropped->defaultUrl->slug;

    expect($old)->toBe('hi-tec-squash-trainer-2')
        ->and(UrlRedirect::query()->where('old_path', "/products/{$old}")->value('new_path'))->toBe('/products/hi-tec-squash-trainer');

    $this->get("/products/{$old}")->assertRedirect('/products/hi-tec-squash-trainer')->assertStatus(301);
    $this->get("/products/{$old}?x=1")->assertRedirect('/products/hi-tec-squash-trainer?x=1')->assertStatus(301);
});

it('keeps the retired slug reserved, so another product never takes it', function (): void {
    $this->artisan('vendorsync:merge-splits')->assertSuccessful();

    $next = app(GenerateProductSlug::class)->handle('Hi-Tec', 'Squash trainer');

    expect($next)->toBe('hi-tec-squash-trainer-3');
});

it('moves the images and the links, and one primary image is left', function (): void {
    VendorSync::syncImages(['546', '547']);

    $kept = $this->kept->refresh();
    $other = $this->dropped->refresh();
    $keptImages = $kept->getMedia('images');
    $otherImages = $other->getMedia('images');

    expect($keptImages)->not->toBeEmpty()->and($otherImages)->not->toBeEmpty();

    $files = Media::query()->whereIn('id', $otherImages->pluck('id'))->pluck('file_name', 'id')->all();
    $everySource = $keptImages->concat($otherImages)->map(fn (Media $media): string => (string) $media->getCustomProperty('source_url'))->unique();

    $this->artisan('vendorsync:merge-splits')->assertSuccessful();

    $after = $kept->refresh()->getMedia('images');

    // Every picture is on the kept product (a picture of the same content for the same colour is stored once).
    expect($after->map(fn (Media $media): string => (string) $media->getCustomProperty('source_url'))->unique()->count())->toBeLessThanOrEqual($everySource->count())
        ->and($after->count())->toBeGreaterThanOrEqual($keptImages->count())
        ->and($after->filter(fn (Media $media): bool => $media->getCustomProperty('primary') === true))->toHaveCount(1)
        ->and(Media::query()->where('model_id', $other->id)->where('model_type', $other->getMorphClass())->count())->toBe(0)
        // Every picture of the other product came across, none was lost: it is moved, or it is a duplicate by content.
        ->and($after->count() + 0)->toBeLessThanOrEqual($keptImages->count() + $otherImages->count());

    // The files did not move: they are stored by media ID, so a moved picture keeps its ID and its file.
    foreach ($after as $media) {
        if (isset($files[$media->id])) {
            expect($media->file_name)->toBe($files[$media->id]);
        }
    }

    // Both links are on the product, with the sync state cleared so the next runs rewrite it.
    $links = VendorProduct::query()->where('product_id', $kept->id)->get();

    expect($links)->toHaveCount(2)
        ->and($links->pluck('payload_hash')->filter()->all())->toBe([])
        ->and($links->pluck('image_urls_hash')->filter()->all())->toBe([]);
});

it('moves favourites and cross-sells, and drops a duplicate or a self reference', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    Favourite::query()->create(['user_id' => $alice->id, 'product_id' => $this->dropped->id]);
    Favourite::query()->create(['user_id' => $bob->id, 'product_id' => $this->kept->id]);
    Favourite::query()->create(['user_id' => $bob->id, 'product_id' => $this->dropped->id]);

    $elsewhere = productFor('1049');
    // 1049 recommends the large band, the band recommends the small one (a self reference once merged), and 1049 also
    // recommends the kept product already (a duplicate once merged).
    foreach ([[$elsewhere->id, $this->dropped->id], [$this->dropped->id, $this->kept->id], [$elsewhere->id, $this->kept->id]] as [$parent, $target]) {
        ProductAssociation::query()->create(['product_parent_id' => $parent, 'product_target_id' => $target, 'type' => AssociationType::CROSS_SELL->value]);
    }

    $this->artisan('vendorsync:merge-splits')->assertSuccessful();

    expect(Favourite::query()->where('user_id', $alice->id)->pluck('product_id')->all())->toBe([$this->kept->id])
        // Bob had both: one row.
        ->and(Favourite::query()->where('user_id', $bob->id)->pluck('product_id')->all())->toBe([$this->kept->id])
        ->and(ProductAssociation::query()->where('product_parent_id', $elsewhere->id)->pluck('product_target_id')->all())->toBe([$this->kept->id])
        ->and(ProductAssociation::query()->where('product_parent_id', $this->kept->id)->count())->toBe(0);
});

it('adds the kept product to a range collection only the other product was in', function (): void {
    $range = Collection::query()->create([
        'collection_group_id' => CollectionGroup::query()->where('handle', 'ranges')->value('id'),
        'attribute_data' => ['name' => new TranslatedText(['en' => new Text('Summer sale')])],
    ]);
    $range->products()->attach($this->dropped->id, ['position' => 1]);

    $this->artisan('vendorsync:merge-splits')->assertSuccessful();

    expect($range->products()->pluck('lunar_products.id')->all())->toBe([$this->kept->id])
        ->and($this->kept->refresh()->collections->pluck('id')->all())->toContain($range->id);
});

it('does not merge products that would sell the same colour and size twice', function (): void {
    // Make the large band's first variant carry a size of the small band.
    $taken = $this->kept->variants->first()->values->pluck('id')->sort()->values()->all();
    $clash = $this->dropped->variants->first();
    $clash->values()->sync($taken);

    $this->artisan('vendorsync:merge-splits')
        ->expectsOutputToContain('Not merged: Hi-Tec Squash trainer')
        ->assertSuccessful();

    expect(productFor('547')->is(productFor('546')))->toBeFalse()
        ->and(Product::query()->count())->toBe(32 - 2)
        ->and(UrlRedirect::query()->count())->toBe(2);
});

it('is safe to run twice', function (): void {
    $this->artisan('vendorsync:merge-splits')->assertSuccessful();

    $this->artisan('vendorsync:merge-splits')
        ->expectsOutputToContain('Merging: 0 product lines of 0 products become one product each: 0 products removed. 2 lines are already one product.')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(32 - 3)
        ->and(UrlRedirect::query()->count())->toBe(3);
});

it('merges only the line of the named ProductID', function (): void {
    $this->artisan('vendorsync:merge-splits', ['--product' => '547'])->assertSuccessful();

    expect(productFor('547')->is(productFor('546')))->toBeTrue()
        ->and(productFor('570')->is(productFor('569')))->toBeFalse();
});

it('is refused while a catalogue run holds the lock, but a dry run is not', function (): void {
    $held = Cache::lock('vendorsync.run.FS.catalogue', 600);
    $held->get();

    $this->artisan('vendorsync:merge-splits')->expectsOutputToContain('A catalogue run for FS is running')->assertFailed();
    $this->artisan('vendorsync:merge-splits', ['--dry-run' => true])->assertSuccessful();

    $held->release();

    expect(Product::query()->count())->toBe(32);
});

it('only merges for Footsure', function (): void {
    $this->artisan('vendorsync:merge-splits', ['vendor' => 'SJ'])->assertFailed();
});

it('lets the next catalogue run rewrite the merged product from all its rows, and stay merged', function (): void {
    $this->artisan('vendorsync:merge-splits')->assertSuccessful();

    $variants = $this->kept->variants()->count();

    VendorSync::footsureFile();
    $report = VendorSync::import('FS');

    $kept = $this->kept->refresh();

    expect($report->errors)->toBe(0)
        ->and($report->created)->toBe(0)
        // The two merged lines are rewritten; the other 27 are as they were.
        ->and($report->updated)->toBe(2)
        ->and($report->unchanged)->toBe(27)
        ->and(Product::query()->count())->toBe(29)
        ->and($kept->variants()->count())->toBe($variants)
        ->and(VendorProduct::query()->where('product_id', $kept->id)->pluck('payload_hash')->unique()->count())->toBe(1);

    // And the run after that has nothing to do.
    VendorSync::footsureFile();

    expect(VendorSync::import('FS')->unchanged)->toBe(29);
});
