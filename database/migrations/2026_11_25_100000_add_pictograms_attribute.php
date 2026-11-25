<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Lunar\FieldTypes\ListField;
use Lunar\Models\Attribute;
use Lunar\Models\AttributeGroup;
use Lunar\Models\Product;
use Lunar\Models\ProductType;

return new class extends Migration
{
    /**
     * The `pictograms` product attribute (data contract, Product attributes), for a database that was seeded before it existed:
     * CatalogueFoundationSeeder runs on the first deploy only. It is created the way the seeder creates it, in the Specification
     * group and mapped to every product type, and does nothing when it is already there.
     *
     * On a fresh database the Specification group does not exist yet (the seeder creates it after the migrations), so this does
     * nothing and the seeder makes the attribute.
     */
    public function up(): void
    {
        $group = AttributeGroup::query()->where('handle', 'product_specification')->first();

        if ($group === null) {
            return;
        }

        $attribute = Attribute::query()->firstOrCreate(
            ['attribute_type' => Product::morphName(), 'handle' => 'pictograms'],
            [
                'attribute_group_id' => $group->id,
                'position' => (int) Attribute::query()->where('attribute_type', Product::morphName())->max('position') + 1,
                'name' => ['en' => 'Pictograms'],
                'description' => ['en' => ''],
                'section' => 'main',
                'type' => ListField::class,
                'required' => false,
                'default_value' => null,
                'configuration' => [],
                'system' => false,
                'searchable' => true,
                'filterable' => false,
            ],
        );

        foreach (ProductType::query()->get() as $productType) {
            $productType->mappedAttributes()->syncWithoutDetaching([$attribute->id]);
        }
    }

    public function down(): void
    {
        $attribute = Attribute::query()->where('attribute_type', Product::morphName())->where('handle', 'pictograms')->first();

        if ($attribute === null) {
            return;
        }

        foreach (ProductType::query()->get() as $productType) {
            $productType->mappedAttributes()->detach($attribute->id);
        }

        $attribute->delete();
    }
};
