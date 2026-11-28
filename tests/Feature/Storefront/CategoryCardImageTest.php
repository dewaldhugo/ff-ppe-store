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
    preg_match('#data-category-grid.*?</ul>#s', $html, $grid);

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

    expect(preg_match_all('#data-category-image(?![-\w])#', $grid))->toBe(count(topLevelHandles()));

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
        ->and(preg_match_all('#data-category-image(?![-\w])#', $grid))->toBe(count(topLevelHandles()));
});

it('refuses a handle that could point outside the images folder', function (): void {
    expect(CategoryCardImage::for(null))->toBeNull()
        ->and(CategoryCardImage::for('../home-hero'))->toBeNull()
        ->and(CategoryCardImage::for('no-such-category'))->toBeNull()
        ->and(CategoryCardImage::for('apparel'))->toMatchArray(['width' => CategoryCardImage::WIDTH, 'height' => CategoryCardImage::HEIGHT]);
});

it('lays the five cards out in one row on desktop, with the subcategories as plain text and no links below', function (): void {
    $html = $this->get(route('shop'))->assertOk()->getContent();

    preg_match('#<ul[^>]*data-category-grid[^>]*>#', $html, $ul);

    $grid = shopGrid($html);

    expect($ul[0] ?? '')->toContain('lg:grid-cols-5')
        ->and(preg_match_all('#data-category-image(?![-\w])#', $grid))->toBe(5)
        ->and($grid)->not->toContain('data-category-children')
        // One link per card: the category itself. The subcategory names are text.
        ->and(substr_count(substr($grid, 0, (int) strpos($grid, '</ul>')), '<a '))->toBe(5)
        ->and($grid)->toContain('Safety Boots');
});

it('zooms the picture on a category card on hover and on keyboard focus, clipped to the card', function (): void {
    $grid = shopGrid($this->get(route('shop'))->assertOk()->getContent());

    preg_match_all('#<li class="([^"]*)" data-category-card>#', $grid, $cards);
    preg_match_all('#<img[^>]*data-category-image[^>]*>#s', $grid, $images);

    expect($cards[1])->toHaveCount(5)->and($images[0])->toHaveCount(5);

    foreach ($cards[1] as $card) {
        // The card is the group the zoom follows, and it clips the zoomed picture to its rounded corners.
        expect($card)->toContain('group')->toContain('overflow-hidden')->toContain('rounded-lg');
    }

    foreach ($images[0] as $image) {
        // A zoom to 110%, smooth, whatever the "reduce motion" setting says (no motion-safe:); also when the card's link has keyboard focus.
        expect($image)->toContain('transition-transform')->toContain('duration-500')
            ->toContain('group-hover:scale-110')->toContain('group-focus-within:scale-110')->not->toContain('motion-safe:')
            ->toContain('object-cover')->toContain('alt=""');
    }
});

it('zooms the pictures on the home page category cards too, which are the same cards', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<div[^>]*data-home-categories.*?</ul>#s', $html, $section);

    expect(substr_count($section[0], 'group-hover:scale-110'))->toBe(5)
        ->and(substr_count($section[0], 'data-category-card'))->toBe(5);
});

it('keeps the zoomed picture inside its own frame, so it never grows over the category name', function (): void {
    $grid = shopGrid($this->get(route('shop'))->assertOk()->getContent());

    preg_match_all('#<div class="([^"]*)" data-category-image-frame>\s*<img([^>]*)>\s*</div>#s', $grid, $frames, PREG_SET_ORDER);

    expect($frames)->toHaveCount(5);

    foreach ($frames as $frame) {
        // The frame has the picture's shape and clips what sticks out of it; the picture fills the frame and is what zooms.
        expect($frame[1])->toContain('aspect-[4/3]')->toContain('w-full')->toContain('overflow-hidden')
            ->and($frame[2])->toContain('h-full')->toContain('w-full')->toContain('object-cover')->toContain('group-hover:scale-110')->not->toContain('aspect-');
    }

    // The frame comes before the words, and the words are not inside it.
    preg_match('#data-category-image-frame>.*?</div>\s*<div class="flex flex-1 flex-col#s', $grid, $order);

    expect($order)->not->toBeEmpty();
});
