<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Media\PlaceholderImageGenerator;
use App\Domain\Catalogue\Options\SharedOptionValues;
use App\Domain\Catalogue\Options\ShoeSizes;
use App\Domain\Catalogue\Urls\GenerateProductSlug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Lunar\Base\Enums\ProductAssociation as AssociationType;
use Lunar\FieldTypes\ListField;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Brand;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductAssociation;
use Lunar\Models\ProductVariant;
use RuntimeException;

/**
 * Loads database/fixtures/catalogue.json into Lunar and the vendor tables in
 * exactly the shape the data contract describes, standing in for the
 * ingestion module during development and in tests.
 *
 * Local and testing environments only. Never seed fixtures in production.
 */
final class FixtureCatalogueSeeder extends Seeder
{
    private const array VENDOR_NAMES = [
        'SJ' => 'Safety Jogger',
        'FS' => 'Footsure',
    ];

    private const array TRANSLATED = ['name', 'title', 'short_description', 'description'];

    private const array LISTS = ['key_points', 'features', 'standards', 'industries', 'certificates'];

    private const array TEXTS = [
        'safety_class', 'toecap', 'midsole', 'outsole', 'upper', 'lining', 'cut',
        'fastening', 'gender', 'country_of_origin', 'video_url',
    ];

    private Language $language;

    private Currency $currency;

    private CustomerGroup $customerGroup;

    /** @var array<string, int> */
    private array $vendorIds = [];

    public function __construct(
        private readonly GenerateProductSlug $generateSlug,
        private readonly PlaceholderImageGenerator $placeholders,
        private readonly SharedOptionValues $optionValues,
    ) {}

    public function run(?string $path = null): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('FixtureCatalogueSeeder may only run in the local and testing environments.');
        }

        $fixture = $this->read($path ?? database_path('fixtures/catalogue.json'));

        $this->language = Language::getDefault();
        $this->currency = Currency::getDefault();
        $this->customerGroup = CustomerGroup::query()->where('handle', 'retail')->sole();

        $productIds = [];
        $brandIds = [];

        // Bulk writers must not sync to search row by row (see CLAUDE.md).
        Product::withoutSyncingToSearch(function () use ($fixture, &$productIds, &$brandIds): void {
            Brand::withoutSyncingToSearch(function () use ($fixture, &$productIds, &$brandIds): void {
                DB::transaction(function () use ($fixture, &$productIds, &$brandIds): void {
                    $related = [];

                    foreach ($fixture['products'] as $data) {
                        $product = $this->product($data);

                        if ($product === null) {
                            continue;
                        }

                        $productIds[] = $product->id;
                        $brandIds[] = $product->brand_id;
                        $related[$product->id] = [
                            $data['vendor_code'],
                            $data['meta']['related_vendor_product_ids'] ?? [],
                        ];
                    }

                    $this->associations($related);
                });
            });
        });

        // One batch reindex for everything that was written.
        Brand::query()->whereIn('id', array_unique($brandIds))->get()->searchable();
        Product::query()->whereIn('id', $productIds)->get()->searchable();
    }

    /**
     * @return array{products: list<array<string, mixed>>}
     */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Fixture file not found: {$path}");
        }

        try {
            return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Fixture file is not valid JSON: {$path}", previous: $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function product(array $data): ?Product
    {
        $vendorId = $this->vendor($data['vendor_code']);

        $alreadySeeded = DB::table('vendor_products')
            ->where('vendor_id', $vendorId)
            ->where('vendor_product_id', $data['vendor_product_id'])
            ->exists();

        if ($alreadySeeded) {
            return null;
        }

        $brand = Brand::query()->firstOrCreate(['name' => $data['brand']]);

        $product = Product::query()->create([
            'product_type_id' => CatalogueKeys::productType($data['product_type'])->id,
            'status' => $data['status'],
            'brand_id' => $brand->id,
            'attribute_data' => $this->attributeData($data['attributes']),
        ]);

        $product->urls()->create([
            'slug' => $this->generateSlug->handle($data['brand'], $data['attributes']['name']),
            'default' => true,
            'language_id' => $this->language->id,
        ]);

        DB::table('vendor_products')->insert([
            'product_id' => $product->id,
            'vendor_id' => $vendorId,
            'vendor_product_id' => $data['vendor_product_id'],
            'commodity_code' => $data['meta']['commodity_code'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([...$data['collections'], ...$data['ranges']] as $handle) {
            $collection = CatalogueKeys::collection($handle);
            $collection->products()->attach($product->id, [
                'position' => $collection->products()->count() + 1,
            ]);
        }

        $product->productOptions()->attach([
            $this->optionValues->option('colour')->id => ['position' => 1],
            $this->optionValues->option('shoe_size')->id => ['position' => 2],
        ]);

        $images = $this->images($product, $data);

        foreach ($data['variants'] as $variantData) {
            $this->variant($product, $vendorId, $variantData, $images);
        }

        return $product;
    }

    /**
     * Null and empty values are left out rather than stored as blanks.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function attributeData(array $attributes): array
    {
        $data = [];

        foreach (self::TRANSLATED as $handle) {
            if (filled($attributes[$handle] ?? null)) {
                $data[$handle] = new TranslatedText(['en' => new Text($attributes[$handle])]);
            }
        }

        foreach (self::LISTS as $handle) {
            if (! empty($attributes[$handle])) {
                $data[$handle] = new ListField(array_values($attributes[$handle]));
            }
        }

        foreach (self::TEXTS as $handle) {
            if (filled($attributes[$handle] ?? null)) {
                $data[$handle] = new Text($attributes[$handle]);
            }
        }

        return $data;
    }

    /**
     * One placeholder image per colour, role "main". The first is the
     * product thumbnail.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int> Media ID by colour name.
     */
    private function images(Product $product, array $data): array
    {
        $size = app()->runningUnitTests() ? 240 : 1600;
        $directory = storage_path('app/private/fixture-placeholders');
        $images = [];

        foreach ($data['colours'] as $index => $colour) {
            $caption = $data['brand'].' '.$data['attributes']['name'].' '.$colour['name'];

            $media = $product
                ->addMedia($this->placeholders->generate($caption, $colour['hex'], $directory, $size))
                ->usingName($caption)
                ->usingFileName(Str::slug($caption).'.png')
                ->withCustomProperties([
                    'colour' => $colour['name'],
                    'role' => 'main',
                    'primary' => $index === 0,
                ])
                ->toMediaCollection(config('lunar.media.collection'));

            $images[$colour['name']] = $media->id;

            $this->optionValues->value('colour', $colour['name'], ['hex' => $colour['hex']], $index + 1);
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $images
     */
    private function variant(Product $product, int $vendorId, array $data, array $images): void
    {
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'tax_class_id' => CatalogueKeys::taxClass($data['tax_class'])->id,
            'sku' => $data['sku'],
            'ean' => $data['ean'],
            'stock' => $data['stock'],
            'backorder' => 0,
            'purchasable' => 'in_stock',
            'shippable' => true,
            'unit_quantity' => 1,
            'weight_value' => $data['weight_kg'],
            'weight_unit' => 'kg',
            'length_value' => $data['length_mm'],
            'length_unit' => 'mm',
            'width_value' => $data['width_mm'],
            'width_unit' => 'mm',
            'height_value' => $data['height_mm'],
            'height_unit' => 'mm',
            'attribute_data' => filled($data['eu_size'] ?? null)
                ? ['eu_size' => new Text((string) $data['eu_size'])]
                : [],
        ]);

        $size = (string) $data['shoe_size'];

        $variant->values()->attach([
            $this->optionValues->value('colour', $data['colour'])->id,
            $this->optionValues->value('shoe_size', $size, ['sort' => ShoeSizes::sort($size)], (int) round((float) $size * 10))->id,
        ]);

        Price::query()->create([
            'priceable_type' => $variant->getMorphClass(),
            'priceable_id' => $variant->id,
            'currency_id' => $this->currency->id,
            'customer_group_id' => $this->customerGroup->id,
            'price' => $data['price'],
            'compare_price' => $data['compare_price'],
            'min_quantity' => 1,
        ]);

        if (isset($images[$data['colour']])) {
            $variant->images()->attach($images[$data['colour']], ['primary' => true, 'position' => 1]);
        }

        DB::table('vendor_offers')->insert([
            'product_variant_id' => $variant->id,
            'vendor_id' => $vendorId,
            'vendor_sku' => $data['meta']['vendor_sku'],
            'vendor_product_name' => $data['meta']['vendor_product_name'],
            'vendor_colour' => $data['meta']['vendor_colour'],
            'vendor_size' => $data['meta']['vendor_size'],
            'ean' => $data['ean'],
            // Straight from the fixture. Cost is never derived from price.
            'cost_price' => $data['cost_price'] ?? null,
            'stock' => $data['stock'],
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function vendor(string $code): int
    {
        return $this->vendorIds[$code] ??= (int) (
            DB::table('vendors')->where('code', $code)->value('id')
            ?? DB::table('vendors')->insertGetId([
                'code' => $code,
                'name' => self::VENDOR_NAMES[$code] ?? $code,
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }

    /**
     * Related vendor product IDs become native cross-sell associations,
     * for the related products that exist.
     *
     * @param  array<int, array{0: string, 1: list<string>}>  $related
     */
    private function associations(array $related): void
    {
        foreach ($related as $productId => [$vendorCode, $vendorProductIds]) {
            if ($vendorProductIds === []) {
                continue;
            }

            $targets = DB::table('vendor_products')
                ->where('vendor_id', $this->vendor($vendorCode))
                ->whereIn('vendor_product_id', $vendorProductIds)
                ->pluck('product_id');

            foreach ($targets as $targetId) {
                ProductAssociation::query()->firstOrCreate([
                    'product_parent_id' => $productId,
                    'product_target_id' => $targetId,
                    'type' => AssociationType::CROSS_SELL->value,
                ]);
            }
        }
    }
}
