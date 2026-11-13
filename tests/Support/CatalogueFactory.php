<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use Illuminate\Support\Str;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Brand;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;

/**
 * Builds small contract-shaped products for cases the fixture file does not
 * contain (missing combinations, a product without options, and so on).
 */
final class CatalogueFactory
{
    /**
     * @param  list<array{colour?: string, size?: string, stock: int}>  $variants
     */
    public static function product(string $name, array $variants, string $status = 'published', int $price = 5000): Product
    {
        $brand = Brand::query()->firstOrCreate(['name' => 'Test Brand']);

        $product = Product::query()->create([
            'product_type_id' => CatalogueKeys::productType('safety-footwear')->id,
            'status' => $status,
            'brand_id' => $brand->id,
            'attribute_data' => [
                'name' => new TranslatedText(['en' => new Text($name)]),
                'title' => new TranslatedText(['en' => new Text($name.' Safety Boot')]),
            ],
        ]);

        $product->urls()->create([
            'slug' => Str::slug('test-brand '.$name),
            'default' => true,
            'language_id' => Language::getDefault()->id,
        ]);

        foreach ($variants as $index => $data) {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'tax_class_id' => CatalogueKeys::taxClass('standard')->id,
                'sku' => 'TEST-'.Str::upper(Str::slug($name)).'-'.$index,
                'stock' => $data['stock'],
                'purchasable' => 'in_stock',
                'shippable' => true,
                'attribute_data' => [],
            ]);

            $values = [];

            if (isset($data['colour'])) {
                $values[] = self::optionValue('colour', $data['colour'])->id;
            }

            if (isset($data['size'])) {
                $values[] = self::optionValue('shoe_size', $data['size'], ['sort' => (float) $data['size']])->id;
            }

            $variant->values()->attach($values);

            Price::query()->create([
                'priceable_type' => $variant->getMorphClass(),
                'priceable_id' => $variant->id,
                'currency_id' => Currency::getDefault()->id,
                'customer_group_id' => CustomerGroup::query()->where('handle', 'retail')->sole()->id,
                'price' => $price,
                'min_quantity' => 1,
            ]);
        }

        return $product->fresh();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function optionValue(string $option, string $name, array $meta = []): ProductOptionValue
    {
        $optionId = ProductOption::query()->where('handle', $option)->sole()->id;

        return ProductOptionValue::query()
            ->where('product_option_id', $optionId)
            ->where('name->en', $name)
            ->first()
            ?? ProductOptionValue::query()->create([
                'product_option_id' => $optionId,
                'name' => ['en' => $name],
                'position' => 0,
                'meta' => $meta,
            ]);
    }
}
