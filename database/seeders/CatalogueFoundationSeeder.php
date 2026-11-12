<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\ListField;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Attribute;
use Lunar\Models\AttributeGroup;
use Lunar\Models\Channel;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Lunar\Models\TaxRate;
use Lunar\Models\TaxRateAmount;
use Lunar\Models\TaxZone;

/**
 * Creates the catalogue structure defined by the data contract in CLAUDE.md.
 *
 * Idempotent and safe to run on every deploy: every record is looked up by
 * its handle (native, or through the catalogue_keys registry) and created
 * only when missing. Existing records are never updated, so names edited in
 * the admin panel survive.
 */
final class CatalogueFoundationSeeder extends Seeder
{
    private const array PRODUCT_TYPES = [
        'safety-footwear' => 'Safety footwear',
        'workwear' => 'Workwear',
        'gloves' => 'Gloves',
        'head-protection' => 'Head protection',
    ];

    /** Handle => [name, VAT percentage in the uk zone, default]. */
    private const array TAX_CLASSES = [
        'standard' => ['Standard rate', 20, true],
        // Individual-use rate. The 20% employer rate is applied at checkout.
        'zero-protective' => ['Protective boots and helmets (zero rate for individuals)', 0, false],
        'zero-childrens' => ["Children's footwear (zero rate)", 0, false],
    ];

    private const string ALL = 'all';

    private const string FOOTWEAR = 'safety-footwear';

    /**
     * Product attributes: handle => [name, field type, product types, group].
     * Order here is the display order in the admin panel.
     */
    private const array PRODUCT_ATTRIBUTES = [
        'name' => ['Name', TranslatedText::class, self::ALL, 'product_details'],
        'title' => ['Title', TranslatedText::class, self::ALL, 'product_details'],
        'short_description' => ['Short description', TranslatedText::class, self::ALL, 'product_details'],
        'description' => ['Description', TranslatedText::class, self::ALL, 'product_details'],
        'key_points' => ['Key points', ListField::class, self::ALL, 'product_details'],
        'safety_class' => ['Safety class', Text::class, self::FOOTWEAR, 'product_specification'],
        'features' => ['Features', ListField::class, self::FOOTWEAR, 'product_specification'],
        'standards' => ['Standards', ListField::class, self::ALL, 'product_specification'],
        'toecap' => ['Toecap', Text::class, self::FOOTWEAR, 'product_specification'],
        'midsole' => ['Midsole', Text::class, self::FOOTWEAR, 'product_specification'],
        'outsole' => ['Outsole', Text::class, self::FOOTWEAR, 'product_specification'],
        'upper' => ['Upper', Text::class, self::FOOTWEAR, 'product_specification'],
        'lining' => ['Lining', Text::class, self::FOOTWEAR, 'product_specification'],
        'cut' => ['Cut', Text::class, self::FOOTWEAR, 'product_specification'],
        'fastening' => ['Fastening', Text::class, self::FOOTWEAR, 'product_specification'],
        'industries' => ['Industries', ListField::class, self::ALL, 'product_specification'],
        'gender' => ['Gender', Text::class, self::ALL, 'product_specification'],
        'country_of_origin' => ['Country of origin', Text::class, self::ALL, 'product_specification'],
        'video_url' => ['Video URL', Text::class, self::ALL, 'product_specification'],
        'certificates' => ['Certificates', ListField::class, self::ALL, 'product_specification'],
    ];

    /** Variant attributes: handle => [name, field type, product types]. */
    private const array VARIANT_ATTRIBUTES = [
        'eu_size' => ['EU size', Text::class, self::FOOTWEAR],
    ];

    private const array PRODUCT_OPTIONS = [
        'colour' => 'Colour',
        'shoe_size' => 'UK size',
        'garment_size' => 'Size',
    ];

    /** Collection group handle => [group name, tree of handle => [name, children]]. */
    private const array COLLECTIONS = [
        'categories' => ['Categories', [
            'safety-footwear' => ['Safety Footwear', [
                'safety-trainers' => ['Safety Trainers', []],
                'safety-shoes' => ['Safety Shoes', []],
                'safety-boots' => ['Safety Boots', []],
            ]],
            'occupational-footwear' => ['Occupational Footwear', []],
            'slippers' => ['Slippers', []],
        ]],
        'ranges' => ['Ranges', [
            'clearance' => ['Clearance', []],
        ]],
    ];

    public function __construct(private readonly CatalogueKeys $keys) {}

    public function run(): void
    {
        CatalogueKeys::flush();

        DB::transaction(function (): void {
            $language = $this->language();
            $this->currency();
            $this->channel();
            $this->customerGroup();

            $taxClasses = $this->taxClasses();
            $this->taxZone($taxClasses);

            $productTypes = $this->productTypes();
            $this->attributes($productTypes);
            $this->productOptions();
            $this->collections($language);
        });

        CatalogueKeys::flush();
    }

    private function language(): Language
    {
        return Language::query()->firstOrCreate(
            ['code' => 'en'],
            ['name' => 'English', 'default' => true],
        );
    }

    private function currency(): void
    {
        Currency::query()->firstOrCreate(
            ['code' => 'GBP'],
            [
                'name' => 'British Pound',
                'exchange_rate' => 1,
                'decimal_places' => 2,
                'enabled' => true,
                'default' => true,
            ],
        );
    }

    private function channel(): void
    {
        Channel::query()->firstOrCreate(
            ['handle' => 'webstore'],
            ['name' => 'Webstore', 'default' => true, 'url' => config('app.url')],
        );
    }

    private function customerGroup(): void
    {
        CustomerGroup::query()->firstOrCreate(
            ['handle' => 'retail'],
            ['name' => 'Retail', 'default' => true],
        );
    }

    /**
     * @return array<string, TaxClass>
     */
    private function taxClasses(): array
    {
        $classes = [];

        foreach (self::TAX_CLASSES as $handle => [$name, , $default]) {
            $classes[$handle] = $this->keyed(
                KeyType::TaxClass,
                $handle,
                fn (): TaxClass => TaxClass::query()->create(['name' => $name, 'default' => $default]),
            );
        }

        return $classes;
    }

    /**
     * @param  array<string, TaxClass>  $taxClasses
     */
    private function taxZone(array $taxClasses): void
    {
        $country = Country::query()->firstOrCreate(
            ['iso3' => 'GBR'],
            [
                'name' => 'United Kingdom',
                'iso2' => 'GB',
                'phonecode' => '44',
                'capital' => 'London',
                'currency' => 'GBP',
                'native' => 'United Kingdom',
                'emoji' => '🇬🇧',
                'emoji_u' => 'U+1F1EC U+1F1E7',
            ],
        );

        /** @var TaxZone $zone */
        $zone = $this->keyed(
            KeyType::TaxZone,
            'uk',
            fn (): TaxZone => TaxZone::query()->create([
                'name' => 'United Kingdom',
                'zone_type' => 'country',
                'price_display' => 'tax_exclusive',
                'active' => true,
                'default' => true,
            ]),
        );

        $zone->countries()->firstOrCreate(['country_id' => $country->id]);

        // One VAT rate for the zone. Looked up by position, not by name,
        // because the name can be edited in the admin panel.
        $rate = $zone->taxRates()->orderBy('id')->first()
            ?? TaxRate::query()->create(['tax_zone_id' => $zone->id, 'name' => 'VAT', 'priority' => 1]);

        foreach (self::TAX_CLASSES as $handle => [, $percentage]) {
            TaxRateAmount::query()->firstOrCreate(
                ['tax_rate_id' => $rate->id, 'tax_class_id' => $taxClasses[$handle]->id],
                ['percentage' => $percentage],
            );
        }
    }

    /**
     * @return array<string, ProductType>
     */
    private function productTypes(): array
    {
        $types = [];

        foreach (self::PRODUCT_TYPES as $handle => $name) {
            $types[$handle] = $this->keyed(
                KeyType::ProductType,
                $handle,
                fn (): ProductType => ProductType::query()->create(['name' => $name]),
            );
        }

        return $types;
    }

    /**
     * @param  array<string, ProductType>  $productTypes
     */
    private function attributes(array $productTypes): void
    {
        $groups = [
            'product_details' => $this->attributeGroup('product_details', 'Details', Product::morphName(), 1),
            'product_specification' => $this->attributeGroup('product_specification', 'Specification', Product::morphName(), 2),
            'variant_details' => $this->attributeGroup('variant_details', 'Variant details', ProductVariant::morphName(), 1),
            'collection_details' => $this->attributeGroup('collection_details', 'Details', Collection::morphName(), 1),
        ];

        $position = 0;

        foreach (self::PRODUCT_ATTRIBUTES as $handle => [$name, $type, $appliesTo, $group]) {
            $attribute = $this->attribute(Product::morphName(), $groups[$group], $handle, $name, $type, ++$position);
            $this->mapToProductTypes($attribute, $appliesTo, $productTypes);
        }

        $position = 0;

        foreach (self::VARIANT_ATTRIBUTES as $handle => [$name, $type, $appliesTo]) {
            $attribute = $this->attribute(ProductVariant::morphName(), $groups['variant_details'], $handle, $name, $type, ++$position);
            $this->mapToProductTypes($attribute, $appliesTo, $productTypes);
        }

        // Lunar's admin panel needs these two on collections.
        $this->attribute(Collection::morphName(), $groups['collection_details'], 'name', 'Name', TranslatedText::class, 1);
        $this->attribute(Collection::morphName(), $groups['collection_details'], 'description', 'Description', TranslatedText::class, 2);
    }

    private function attributeGroup(string $handle, string $name, string $attributableType, int $position): AttributeGroup
    {
        return AttributeGroup::query()->firstOrCreate(
            ['handle' => $handle],
            [
                'attributable_type' => $attributableType,
                'name' => collect(['en' => $name]),
                'position' => $position,
            ],
        );
    }

    /**
     * @param  class-string  $type
     */
    private function attribute(string $attributeType, AttributeGroup $group, string $handle, string $name, string $type, int $position): Attribute
    {
        return Attribute::query()->firstOrCreate(
            ['attribute_type' => $attributeType, 'handle' => $handle],
            [
                'attribute_group_id' => $group->id,
                'position' => $position,
                'name' => ['en' => $name],
                'description' => ['en' => ''],
                'section' => 'main',
                'type' => $type,
                'required' => $handle === 'name',
                'default_value' => null,
                'configuration' => $type === ListField::class ? [] : ['richtext' => false],
                'system' => $handle === 'name',
                'searchable' => true,
                'filterable' => false,
            ],
        );
    }

    /**
     * @param  array<string, ProductType>  $productTypes
     */
    private function mapToProductTypes(Attribute $attribute, string $appliesTo, array $productTypes): void
    {
        $targets = $appliesTo === self::ALL ? $productTypes : [$productTypes[$appliesTo]];

        foreach ($targets as $productType) {
            $productType->mappedAttributes()->syncWithoutDetaching([$attribute->id]);
        }
    }

    private function productOptions(): void
    {
        foreach (self::PRODUCT_OPTIONS as $handle => $name) {
            ProductOption::query()->firstOrCreate(
                ['handle' => $handle],
                [
                    'name' => ['en' => $name],
                    'label' => ['en' => $name],
                    'shared' => true,
                ],
            );
        }
    }

    private function collections(Language $language): void
    {
        foreach (self::COLLECTIONS as $groupHandle => [$groupName, $tree]) {
            $group = CollectionGroup::query()->firstOrCreate(
                ['handle' => $groupHandle],
                ['name' => $groupName],
            );

            $this->collectionTree($tree, $group, null, $language);
        }
    }

    /**
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $tree
     */
    private function collectionTree(array $tree, CollectionGroup $group, ?Collection $parent, Language $language): void
    {
        foreach ($tree as $handle => [$name, $children]) {
            /** @var Collection $collection */
            $collection = $this->keyed(
                KeyType::Collection,
                $handle,
                function () use ($name, $handle, $group, $parent, $language): Collection {
                    $collection = Collection::create([
                        'collection_group_id' => $group->id,
                        'attribute_data' => [
                            'name' => new TranslatedText(['en' => new Text($name)]),
                        ],
                    ], $parent);

                    // The contract fixes the slug to the handle.
                    $collection->urls()->create([
                        'slug' => $handle,
                        'default' => true,
                        'language_id' => $language->id,
                    ]);

                    return $collection;
                },
            );

            $this->collectionTree($children, $group, $collection, $language);
        }
    }

    /**
     * Return the record registered under a handle, creating and registering
     * it when the handle is new or its record has been deleted.
     *
     * @param  callable(): Model  $create
     */
    private function keyed(KeyType $type, string $handle, callable $create): Model
    {
        $existing = $this->keys->find($type, $handle);

        if ($existing !== null) {
            return $existing;
        }

        $model = $create();
        $this->keys->register($type, $handle, $model);

        return $model;
    }
}
