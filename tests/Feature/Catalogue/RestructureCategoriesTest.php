<?php

declare(strict_types=1);

use App\Domain\Catalogue\Collections\CategoryTree;
use App\Domain\Catalogue\Keys\CatalogueKey;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use App\Domain\Catalogue\Keys\UnknownCatalogueKey;
use App\Domain\Catalogue\Urls\CategoryUrl;
use App\Models\UrlRedirect;
use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Language;
use Lunar\Models\Product;
use Lunar\Models\Url;
use Tests\Support\CatalogueFactory;

/**
 * Put the seeded tree back into the shape it had before the full category
 * tree: three children under safety-footwear, everything else at the top
 * level, with the old names.
 */
function oldCategoryTree(): void
{
    $rename = function (string $handle, string $name): void {
        $collection = CatalogueKeys::collection($handle);
        $collection->update(['attribute_data' => ['name' => new TranslatedText(['en' => new Text($name)])]]);
    };

    foreach (['occupational-footwear', 'casual-footwear', 'wellingtons'] as $handle) {
        CatalogueKeys::collection($handle)->saveAsRoot();
    }

    // The old order under safety-footwear: trainers, shoes, boots.
    $parent = CatalogueKeys::collection('safety-footwear');
    CatalogueKeys::collection('safety-boots')->appendToNode($parent->refresh())->save();

    $rename('wellingtons', 'Wellingtons');
    $rename('casual-footwear', 'Casual Footwear');

    UrlRedirect::query()->delete();
    CatalogueKeys::flush();
}

/**
 * @return array<string, list<string>> Top-level handle => child handles, in menu order.
 */
function categoryTreeHandles(): array
{
    $tree = Collection::query()
        ->where('collection_group_id', CollectionGroup::query()->where('handle', 'categories')->sole()->id)
        ->defaultOrder()
        ->get()
        ->toTree();

    $handle = fn (Collection $collection): string => (string) CatalogueKeys::handleFor(KeyType::Collection, $collection);
    $handles = [];

    foreach ($tree as $top) {
        $handles[$handle($top)] = $top->children->map($handle)->all();
    }

    return $handles;
}

/**
 * @return array<string, list<string>>
 */
function contractTreeHandles(): array
{
    return array_map(
        fn (array $node): array => array_keys($node[1]),
        CategoryTree::GROUPS[CategoryTree::CATEGORIES][1],
    );
}

it('changes nothing on a freshly seeded tree', function (): void {
    $this->artisan('catalogue:restructure-categories')
        ->expectsOutputToContain('0 change(s)')
        ->assertSuccessful();

    expect(categoryTreeHandles())->toBe(contractTreeHandles());
});

it('moves an old tree into the contract tree, in menu order', function (): void {
    oldCategoryTree();

    expect(CatalogueKeys::collection('occupational-footwear')->parent_id)->toBeNull();

    $this->artisan('catalogue:restructure-categories')->assertSuccessful();

    expect(categoryTreeHandles())->toBe(contractTreeHandles());
});

it('renames a category that still has its old contract name, and no other', function (): void {
    oldCategoryTree();

    $boots = CatalogueKeys::collection('safety-boots');
    $boots->update(['attribute_data' => ['name' => new TranslatedText(['en' => new Text('Work Boots (renamed by admin)')])]]);

    $this->artisan('catalogue:restructure-categories')->assertSuccessful();

    CatalogueKeys::flush();

    expect(CatalogueKeys::collection('wellingtons')->translateAttribute('name'))->toBe('Wellingtons & Waders')
        ->and(CatalogueKeys::collection('casual-footwear')->translateAttribute('name'))->toBe('Casual, Formal & Work Shoes')
        ->and(CatalogueKeys::collection('safety-boots')->translateAttribute('name'))->toBe('Work Boots (renamed by admin)');
});

it('keeps a name edited in the admin panel on a renamed handle', function (): void {
    oldCategoryTree();

    CatalogueKeys::collection('wellingtons')->update(['attribute_data' => [
        'name' => new TranslatedText(['en' => new Text('Wellies')]),
    ]]);

    $this->artisan('catalogue:restructure-categories')->assertSuccessful();

    CatalogueKeys::flush();

    expect(CatalogueKeys::collection('wellingtons')->translateAttribute('name'))->toBe('Wellies');
});

it('records a 301 from the old path of every moved category', function (): void {
    oldCategoryTree();

    $this->artisan('catalogue:restructure-categories')->assertSuccessful();

    $redirects = UrlRedirect::query()->pluck('new_path', 'old_path')->all();

    expect($redirects)->toMatchArray([
        '/shop/occupational-footwear' => '/shop/safety-footwear/occupational-footwear',
        '/shop/wellingtons' => '/shop/non-safety-footwear/wellingtons',
        '/shop/casual-footwear' => '/shop/non-safety-footwear/casual-footwear',
    ])->and(app(CategoryUrl::class)->forHandle('occupational-footwear'))->toEndWith('/shop/safety-footwear/occupational-footwear');
});

it('is safe to run twice', function (): void {
    oldCategoryTree();

    $this->artisan('catalogue:restructure-categories')->assertSuccessful();
    $redirects = UrlRedirect::query()->count();

    $this->artisan('catalogue:restructure-categories')
        ->expectsOutputToContain('0 change(s)')
        ->assertSuccessful();

    expect(UrlRedirect::query()->count())->toBe($redirects)
        ->and(categoryTreeHandles())->toBe(contractTreeHandles());
});

it('writes nothing in a dry run', function (): void {
    oldCategoryTree();
    $before = categoryTreeHandles();

    $this->artisan('catalogue:restructure-categories --dry-run')
        ->expectsOutputToContain('[dry run] Moved occupational-footwear under safety-footwear')
        ->assertSuccessful();

    expect(categoryTreeHandles())->toBe($before)
        ->and(UrlRedirect::query()->count())->toBe(0);
});

/**
 * A category the contract has since dropped, as an older database still has it: under
 * non-safety-footwear, with its URL and registry row.
 */
function oldRetiredCategory(string $handle, string $name): Collection
{
    $collection = Collection::create([
        'collection_group_id' => CollectionGroup::query()->where('handle', 'categories')->sole()->id,
        'attribute_data' => ['name' => new TranslatedText(['en' => new Text($name)])],
    ], CatalogueKeys::collection('non-safety-footwear'));

    $collection->urls()->create(['slug' => $handle, 'default' => true, 'language_id' => Language::getDefault()->id]);
    app(CatalogueKeys::class)->register(KeyType::Collection, $handle, $collection);

    return $collection;
}

/**
 * @param  list<Collection>  $in
 */
function productIn(string $name, array $in, string $status = 'published'): Product
{
    $product = CatalogueFactory::product($name, [['stock' => 5]], $status);

    foreach ($in as $collection) {
        $collection->products()->attach($product->id);
    }

    return $product;
}

it('retires the dropped categories: deletes them, drafts products left in no category and keeps the others', function (): void {
    $slippers = oldRetiredCategory('slippers', 'Slippers');
    $plimsolls = oldRetiredCategory('plimsolls', 'Plimsolls');
    $sandals = oldRetiredCategory('sandals-summer-shoes', 'Sandals & Summer Shoes');
    $casual = CatalogueKeys::collection('casual-footwear');
    $clearance = CatalogueKeys::collection('clearance');

    $onlySlippers = productIn('Only Slipper', [$slippers]);
    $slipperAndCasual = productIn('Slipper And Casual', [$slippers, $casual]);
    $twoRetired = productIn('Two Retired', [$plimsolls, $sandals]);
    // A range is not a category: a product in a retired category and in Clearance only is still left in no category.
    $retiredAndClearance = productIn('Retired And Clearance', [$sandals, $clearance]);
    $untouched = productIn('Plain Casual', [$casual]);
    $alreadyDraft = productIn('Draft Slipper', [$slippers], 'draft');

    $this->artisan('catalogue:restructure-categories')
        ->expectsOutputToContain('Retired slippers')
        ->expectsOutputToContain('Retired plimsolls')
        ->expectsOutputToContain('Retired sandals-summer-shoes')
        ->assertSuccessful();

    CatalogueKeys::flush();

    foreach (CategoryTree::RETIRED as $handle) {
        expect(CatalogueKey::query()->where('handle', $handle)->exists())->toBeFalse($handle)
            ->and(fn () => CatalogueKeys::collection($handle))->toThrow(UnknownCatalogueKey::class);
    }

    expect(Collection::query()->whereIn('id', [$slippers->id, $plimsolls->id, $sandals->id])->count())->toBe(0)
        ->and(DB::table((new Url)->getTable())->where('element_type', $slippers->getMorphClass())->whereIn('element_id', [$slippers->id, $plimsolls->id, $sandals->id])->count())->toBe(0)
        ->and(categoryTreeHandles())->toBe(contractTreeHandles())
        // No category left: drafted. Another category left: still published, and in it.
        ->and($onlySlippers->refresh()->status)->toBe('draft')
        ->and($twoRetired->refresh()->status)->toBe('draft')
        ->and($retiredAndClearance->refresh()->status)->toBe('draft')
        ->and($alreadyDraft->refresh()->status)->toBe('draft')
        ->and($slipperAndCasual->refresh()->status)->toBe('published')
        ->and($slipperAndCasual->collections->pluck('id')->all())->toBe([$casual->id])
        ->and($untouched->refresh()->status)->toBe('published')
        // Products stay in the database, and keep their other collections (Clearance is a range).
        ->and($retiredAndClearance->collections->pluck('id')->all())->toBe([$clearance->id]);

    // The old pages are gone, and a retired path is not redirected.
    $this->get('/shop/non-safety-footwear/slippers')->assertNotFound();
    $this->get('/shop/non-safety-footwear/sandals-summer-shoes')->assertNotFound();
});

it('retires nothing the second time', function (): void {
    oldRetiredCategory('slippers', 'Slippers');

    $this->artisan('catalogue:restructure-categories')->expectsOutputToContain('Retired slippers')->assertSuccessful();

    $this->artisan('catalogue:restructure-categories')
        ->expectsOutputToContain('0 change(s)')
        ->assertSuccessful();
});

it('only reports what it would retire in a dry run', function (): void {
    $slippers = oldRetiredCategory('slippers', 'Slippers');
    $product = productIn('Only Slipper', [$slippers]);

    $this->artisan('catalogue:restructure-categories --dry-run')
        ->expectsOutputToContain('[dry run] Retired slippers: deleted the category (1 product link(s)), drafted 1 product(s) left in no category')
        ->assertSuccessful();

    CatalogueKeys::flush();

    expect(Collection::query()->whereKey($slippers->id)->exists())->toBeTrue()
        ->and(CatalogueKeys::collection('slippers')->id)->toBe($slippers->id)
        ->and($product->refresh()->status)->toBe('published')
        ->and($product->collections->pluck('id')->all())->toBe([$slippers->id]);
});

it('tidies a registry row of a retired category whose record is already gone', function (): void {
    $slippers = oldRetiredCategory('slippers', 'Slippers');
    CatalogueKey::query()->where('handle', 'slippers')->update(['model_id' => 999999]);
    CatalogueKeys::flush();

    $this->artisan('catalogue:restructure-categories')->assertSuccessful();

    expect(CatalogueKey::query()->where('handle', 'slippers')->exists())->toBeFalse()
        // The orphaned collection record itself is not the registry's to delete.
        ->and(Collection::query()->whereKey($slippers->id)->exists())->toBeTrue();
});

it('fails and names the category when the registry points at a deleted record', function (): void {
    CatalogueKey::query()->where('handle', 'apparel')->update(['model_id' => 999999]);
    CatalogueKeys::flush();

    $this->artisan('catalogue:restructure-categories')
        ->expectsOutputToContain("Category 'apparel' does not exist")
        ->assertFailed();
});
