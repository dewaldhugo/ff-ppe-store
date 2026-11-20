<?php

declare(strict_types=1);

use App\Domain\Catalogue\Collections\CategoryTree;
use App\Domain\Catalogue\Keys\CatalogueKey;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use App\Domain\Catalogue\Keys\UnknownCatalogueKey;
use Database\Seeders\CatalogueFoundationSeeder;
use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\ListField;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Attribute;
use Lunar\Models\Channel;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Lunar\Models\TaxZone;

/**
 * Row count and latest update time for every table the seeder writes to.
 *
 * @return array<string, array{int, ?string}>
 */
function foundationSnapshot(): array
{
    $prefix = config('lunar.database.table_prefix');

    $tables = [
        'catalogue_keys',
        ...array_map(fn (string $table): string => $prefix.$table, [
            'languages', 'currencies', 'channels', 'customer_groups', 'countries',
            'tax_classes', 'tax_zones', 'tax_zone_countries', 'tax_rates', 'tax_rate_amounts',
            'product_types', 'attribute_groups', 'attributes', 'attributables',
            'product_options', 'product_option_values',
            'collection_groups', 'collections', 'urls', 'channelables',
        ]),
    ];

    $snapshot = [];

    foreach ($tables as $table) {
        $snapshot[$table] = [
            DB::table($table)->count(),
            DB::table($table)->max('updated_at'),
        ];
    }

    return $snapshot;
}

// The foundation data is seeded once per test run (see Tests\TestCase).

it('creates the channel, currency, language and customer group', function (): void {
    expect(Channel::query()->where('handle', 'webstore')->where('default', true)->exists())->toBeTrue()
        ->and(Channel::query()->count())->toBe(1)
        ->and(Currency::query()->count())->toBe(1)
        ->and(Language::query()->where('code', 'en')->where('default', true)->exists())->toBeTrue()
        ->and(CustomerGroup::query()->where('handle', 'retail')->where('default', true)->exists())->toBeTrue();

    $gbp = Currency::query()->where('code', 'GBP')->sole();

    expect($gbp->default)->toBeTruthy()
        ->and($gbp->decimal_places)->toBe(2);
});

it('creates the uk tax zone with a rate for each tax class', function (string $handle, float $percentage): void {
    $zone = CatalogueKeys::taxZone('uk');
    $class = CatalogueKeys::taxClass($handle);

    $amounts = $zone->taxAmounts()->where('tax_class_id', $class->id)->get();

    expect($zone->default)->toBeTrue()
        ->and(TaxZone::getDefault()->id)->toBe($zone->id)
        ->and($zone->countries()->count())->toBe(1)
        ->and($amounts)->toHaveCount(1)
        ->and((float) $amounts->first()->percentage)->toBe($percentage);
})->with([
    ['standard', 20.0],
    ['zero-protective', 0.0],
    ['zero-childrens', 0.0],
]);

it('makes standard the default tax class', function (): void {
    expect(TaxClass::getDefault()->id)->toBe(CatalogueKeys::taxClass('standard')->id);
});

it('resolves every product type handle in the contract', function (string $handle): void {
    expect(CatalogueKeys::productType($handle))->toBeInstanceOf(ProductType::class);
})->with(['safety-footwear', 'workwear', 'gloves', 'head-protection', 'ppe', 'accessories']);

it('resolves every collection handle in the contract', function (string $handle, string $group, ?string $parent): void {
    $collection = CatalogueKeys::collection($handle);

    expect($collection)->toBeInstanceOf(Collection::class)
        ->and($collection->group->handle)->toBe($group)
        ->and($collection->defaultUrl->slug)->toBe($handle)
        ->and($collection->parent_id)->toBe($parent === null ? null : CatalogueKeys::collection($parent)->id);
})->with([
    ['safety-footwear', 'categories', null],
    ['safety-boots', 'categories', 'safety-footwear'],
    ['safety-shoes', 'categories', 'safety-footwear'],
    ['safety-trainers', 'categories', 'safety-footwear'],
    ['safety-wellingtons', 'categories', 'safety-footwear'],
    ['occupational-footwear', 'categories', 'safety-footwear'],
    ['metal-free-metatarsal', 'categories', 'safety-footwear'],
    ['non-safety-footwear', 'categories', null],
    ['casual-footwear', 'categories', 'non-safety-footwear'],
    ['boots', 'categories', 'non-safety-footwear'],
    ['sports-trainers', 'categories', 'non-safety-footwear'],
    ['hiking-outdoor', 'categories', 'non-safety-footwear'],
    ['wellingtons', 'categories', 'non-safety-footwear'],
    ['apparel', 'categories', null],
    ['jackets-fleeces', 'categories', 'apparel'],
    ['trousers-shorts', 'categories', 'apparel'],
    ['tshirts-polos-shirts', 'categories', 'apparel'],
    ['sweatshirts-hoodies', 'categories', 'apparel'],
    ['activewear-loungewear', 'categories', 'apparel'],
    ['base-layers', 'categories', 'apparel'],
    ['coveralls', 'categories', 'apparel'],
    ['ppe-gear', 'categories', null],
    ['gloves', 'categories', 'ppe-gear'],
    ['eye-face-protection', 'categories', 'ppe-gear'],
    ['fall-protection', 'categories', 'ppe-gear'],
    ['overshoes', 'categories', 'ppe-gear'],
    ['hard-hats-helmets', 'categories', 'ppe-gear'],
    ['hearing-protection', 'categories', 'ppe-gear'],
    ['respiratory-protection', 'categories', 'ppe-gear'],
    ['other-ppe', 'categories', 'ppe-gear'],
    ['accessories', 'categories', null],
    ['socks', 'categories', 'accessories'],
    ['hats-beanies-scarves', 'categories', 'accessories'],
    ['insoles-laces-shoe-care', 'categories', 'accessories'],
    ['bags-wallets', 'categories', 'accessories'],
    ['sunglasses', 'categories', 'accessories'],
    ['other-accessories', 'categories', 'accessories'],
    ['clearance', 'ranges', null],
]);

it('seeds the five top-level categories and 32 leaves in menu order', function (): void {
    $tree = Collection::query()
        ->where('collection_group_id', CollectionGroup::query()->where('handle', 'categories')->sole()->id)
        ->defaultOrder()
        ->get()
        ->toTree();

    $handle = fn (Collection $collection): string => (string) CatalogueKeys::handleFor(KeyType::Collection, $collection);

    expect($tree->map($handle)->all())->toBe(['safety-footwear', 'non-safety-footwear', 'apparel', 'ppe-gear', 'accessories'])
        ->and($tree->first()->children->map($handle)->all())->toBe([
            'safety-boots', 'safety-shoes', 'safety-trainers', 'safety-wellingtons', 'occupational-footwear', 'metal-free-metatarsal',
        ])
        ->and($tree->get(1)->children->map($handle)->all())->toBe([
            'casual-footwear', 'boots', 'sports-trainers', 'hiking-outdoor', 'wellingtons',
        ])
        ->and($tree->sum(fn (Collection $top): int => $top->children->count()))->toBe(32);
});

it('creates both collection groups', function (): void {
    expect(CollectionGroup::query()->pluck('handle')->sort()->values()->all())->toBe(['categories', 'ranges']);
});

it('creates every product attribute with its contract type', function (string $handle, string $type, bool $footwearOnly): void {
    $attribute = Attribute::query()->where('attribute_type', 'product')->where('handle', $handle)->sole();

    $mappedTo = fn (string $productType): bool => CatalogueKeys::productType($productType)
        ->productAttributes()->where('handle', $handle)->exists();

    expect($attribute->type)->toBe($type)
        ->and($mappedTo('safety-footwear'))->toBeTrue()
        ->and($mappedTo('workwear'))->toBe(! $footwearOnly)
        ->and($mappedTo('gloves'))->toBe(! $footwearOnly)
        ->and($mappedTo('head-protection'))->toBe(! $footwearOnly)
        ->and($mappedTo('ppe'))->toBe(! $footwearOnly)
        ->and($mappedTo('accessories'))->toBe(! $footwearOnly);
})->with([
    ['name', TranslatedText::class, false],
    ['title', TranslatedText::class, false],
    ['short_description', TranslatedText::class, false],
    ['description', TranslatedText::class, false],
    ['safety_class', Text::class, true],
    ['features', ListField::class, true],
    ['standards', ListField::class, false],
    ['toecap', Text::class, true],
    ['midsole', Text::class, true],
    ['outsole', Text::class, true],
    ['upper', Text::class, true],
    ['lining', Text::class, true],
    ['cut', Text::class, true],
    ['industries', ListField::class, false],
    ['gender', Text::class, false],
    ['key_points', ListField::class, false],
    ['fastening', Text::class, true],
    ['country_of_origin', Text::class, false],
    ['video_url', Text::class, false],
    ['certificates', ListField::class, false],
]);

it('creates eu_size as a variant attribute on safety footwear only', function (): void {
    $attribute = Attribute::query()
        ->where('attribute_type', ProductVariant::morphName())
        ->where('handle', 'eu_size')
        ->sole();

    expect($attribute->type)->toBe(Text::class)
        ->and(CatalogueKeys::productType('safety-footwear')->variantAttributes()->pluck('handle')->all())->toBe(['eu_size'])
        ->and(CatalogueKeys::productType('workwear')->variantAttributes()->count())->toBe(0);
});

it('creates the shared product options', function (string $handle): void {
    $option = ProductOption::query()->where('handle', $handle)->sole();

    expect((bool) $option->shared)->toBeTrue();
})->with(['colour', 'shoe_size', 'garment_size']);

it('changes nothing when run a second time', function (): void {
    $before = foundationSnapshot();

    $this->travel(1)->hours();
    $this->seed(CatalogueFoundationSeeder::class);

    expect(foundationSnapshot())->toBe($before);
});

it('keeps names edited in the admin and creates nothing new', function (): void {
    $taxClass = CatalogueKeys::taxClass('zero-protective');
    $taxClass->update(['name' => 'PPE boots (renamed by admin)']);

    $productType = CatalogueKeys::productType('safety-footwear');
    $productType->update(['name' => 'Footwear (renamed by admin)']);

    $zone = CatalogueKeys::taxZone('uk');
    $zone->update(['name' => 'UK (renamed by admin)']);
    $zone->taxRates()->first()->update(['name' => 'UK VAT (renamed by admin)']);

    $collection = CatalogueKeys::collection('safety-boots');
    $collection->update(['attribute_data' => [
        'name' => new TranslatedText(['en' => new Text('Work Boots (renamed by admin)')]),
    ]]);

    $before = foundationSnapshot();

    $this->travel(1)->hours();
    $this->seed(CatalogueFoundationSeeder::class);

    expect(foundationSnapshot())->toBe($before)
        ->and(CatalogueKeys::taxClass('zero-protective')->name)->toBe('PPE boots (renamed by admin)')
        ->and(CatalogueKeys::taxClass('zero-protective')->id)->toBe($taxClass->id)
        ->and(CatalogueKeys::productType('safety-footwear')->name)->toBe('Footwear (renamed by admin)')
        ->and(CatalogueKeys::taxZone('uk')->name)->toBe('UK (renamed by admin)')
        ->and(CatalogueKeys::collection('safety-boots')->translateAttribute('name'))->toBe('Work Boots (renamed by admin)');
});

it('throws a clear exception for an unknown handle', function (): void {
    CatalogueKeys::taxClass('reduced');
})->throws(UnknownCatalogueKey::class, 'No tax class is registered with the handle "reduced"');

it('throws when a registered record has been deleted', function (): void {
    CatalogueKey::query()->where('handle', 'gloves')->update(['model_id' => 999999]);
    CatalogueKeys::flush();

    CatalogueKeys::productType('gloves');
})->throws(UnknownCatalogueKey::class, 'no longer exists');

it('reports the handle registered for a record', function (): void {
    $collection = CatalogueKeys::collection('casual-footwear');

    expect(CatalogueKeys::handleFor(KeyType::Collection, $collection))->toBe('casual-footwear');
});

it('does not create the retired categories', function (): void {
    foreach (CategoryTree::RETIRED as $handle) {
        expect(fn () => CatalogueKeys::collection($handle))->toThrow(UnknownCatalogueKey::class)
            ->and(CatalogueKey::query()->where('handle', $handle)->exists())->toBeFalse()
            ->and(array_keys(CategoryTree::parents()))->not->toContain($handle);
    }

    expect(CategoryTree::RETIRED)->toBe(['slippers', 'plimsolls', 'sandals-summer-shoes']);
});
