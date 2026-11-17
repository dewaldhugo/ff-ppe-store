<?php

declare(strict_types=1);

use App\Domain\Catalogue\Collections\CategoryTree;
use App\Domain\Catalogue\Keys\CatalogueKey;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use App\Domain\Catalogue\Urls\CategoryUrl;
use App\Models\UrlRedirect;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;

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

    foreach (['occupational-footwear', 'slippers', 'plimsolls', 'casual-footwear', 'wellingtons'] as $handle) {
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
        '/shop/slippers' => '/shop/non-safety-footwear/slippers',
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

it('fails and names the category when the registry points at a deleted record', function (): void {
    CatalogueKey::query()->where('handle', 'apparel')->update(['model_id' => 999999]);
    CatalogueKeys::flush();

    $this->artisan('catalogue:restructure-categories')
        ->expectsOutputToContain("Category 'apparel' does not exist")
        ->assertFailed();
});
