<?php

declare(strict_types=1);

use Lunar\FieldTypes\ListField;
use Lunar\Models\Attribute;
use Lunar\Models\Product;
use Lunar\Models\ProductType;

// The `pictograms` attribute for a database seeded before it existed (CatalogueFoundationSeeder runs on the first deploy only).

/** The migration, loaded from its file. */
function pictogramsMigration(): object
{
    return require database_path('migrations/2026_11_25_100000_add_pictograms_attribute.php');
}

function pictogramsAttribute(): ?Attribute
{
    return Attribute::query()->where('attribute_type', Product::morphName())->where('handle', 'pictograms')->first();
}

it('creates the pictograms attribute in the Specification group, mapped to every product type, when it is missing', function (): void {
    // A production database seeded before the attribute existed.
    $existing = pictogramsAttribute();

    if ($existing !== null) {
        foreach (ProductType::query()->get() as $productType) {
            $productType->mappedAttributes()->detach($existing->id);
        }

        $existing->delete();
    }

    expect(pictogramsAttribute())->toBeNull();

    pictogramsMigration()->up();

    $attribute = pictogramsAttribute();

    expect($attribute)->not->toBeNull()
        ->and($attribute->type)->toBe(ListField::class)
        ->and($attribute->attributeGroup->handle)->toBe('product_specification')
        ->and($attribute->translate('name'))->toBe('Pictograms')
        ->and($attribute->required)->toBeFalse();

    foreach (ProductType::query()->get() as $productType) {
        expect($productType->mappedAttributes()->where('handle', 'pictograms')->exists())->toBeTrue();
    }
});

it('does nothing when the attribute is already there, however often it runs', function (): void {
    $before = Attribute::query()->count();
    $id = pictogramsAttribute()?->id;

    pictogramsMigration()->up();
    pictogramsMigration()->up();

    expect(pictogramsAttribute()?->id)->toBe($id)
        ->and(Attribute::query()->count())->toBe($before)
        ->and(Attribute::query()->where('handle', 'pictograms')->where('attribute_type', Product::morphName())->count())->toBe(1);
});

it('can be taken back', function (): void {
    pictogramsMigration()->up();
    pictogramsMigration()->down();

    expect(pictogramsAttribute())->toBeNull();

    pictogramsMigration()->down();

    expect(pictogramsAttribute())->toBeNull();
});
