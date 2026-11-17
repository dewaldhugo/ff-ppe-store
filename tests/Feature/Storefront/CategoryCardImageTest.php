<?php

declare(strict_types=1);

use App\Domain\Catalogue\Collections\CategoryTree;
use App\Domain\Catalogue\Menu\CategoryCardImage;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Language;

/**
 * The handles of the five top-level categories, from the one tree definition.
 *
 * @return list<string>
 */
function topLevelHandles(): array
{
    return array_keys(CategoryTree::GROUPS[CategoryTree::CATEGORIES][1]);
}

/**
 * The card list of a rendered /shop page.
 */
function shopGrid(string $html): string
{
    // The grid holds nested lists (the subcategory links), so it runs to the end of the page content.
    preg_match('#data-category-grid.*?</main>#s', $html, $grid);

    return $grid[0] ?? '';
}

it('has a picture file for every top-level category', function (): void {
    foreach (topLevelHandles() as $handle) {
        $path = public_path(CategoryCardImage::DIRECTORY.'/'.$handle.'.jpg');

        expect(is_file($path))->toBeTrue("Missing category image for {$handle}");

        [$width, $height] = getimagesize($path);

        expect([$width, $height])->toBe([CategoryCardImage::WIDTH, CategoryCardImage::HEIGHT], $handle);
    }
});

it('shows one decorative picture on each top-level category card on /shop', function (): void {
    $grid = shopGrid($this->get(route('shop'))->assertOk()->getContent());

    expect(substr_count($grid, 'data-category-image'))->toBe(count(topLevelHandles()));

    foreach (topLevelHandles() as $handle) {
        expect($grid)->toContain(asset(CategoryCardImage::DIRECTORY.'/'.$handle.'.jpg'));
    }

    preg_match_all('#<img[^>]*data-category-image[^>]*>#s', $grid, $images);

    foreach ($images[0] as $img) {
        expect($img)->toContain('alt=""')->toContain('loading="lazy"')->toContain('width="')->toContain('height="');
    }
});

it('leaves a card without a picture when its category has no file', function (): void {
    $collection = Collection::create([
        'collection_group_id' => CollectionGroup::query()->where('handle', 'categories')->sole()->id,
        'attribute_data' => ['name' => new TranslatedText(['en' => new Text('Test Hi-Vis')])],
    ]);
    $collection->urls()->create(['slug' => 'test-hi-vis', 'default' => true, 'language_id' => Language::getDefault()->id]);

    $grid = shopGrid($this->get(route('shop'))->assertOk()->getContent());

    expect($grid)->toContain('Test Hi-Vis')
        ->and(substr_count($grid, 'data-category-image'))->toBe(count(topLevelHandles()));
});

it('refuses a handle that could point outside the images folder', function (): void {
    expect(CategoryCardImage::for(null))->toBeNull()
        ->and(CategoryCardImage::for('../home-hero'))->toBeNull()
        ->and(CategoryCardImage::for('no-such-category'))->toBeNull()
        ->and(CategoryCardImage::for('apparel'))->toMatchArray(['width' => CategoryCardImage::WIDTH, 'height' => CategoryCardImage::HEIGHT]);
});
