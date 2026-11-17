<?php

declare(strict_types=1);

use App\Domain\Catalogue\Menu\CategoryMenu;
use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Language;

function addCategory(string $name, string $slug, ?Collection $parent = null, string $group = 'categories'): Collection
{
    $collection = Collection::create([
        'collection_group_id' => CollectionGroup::query()->where('handle', $group)->sole()->id,
        'attribute_data' => ['name' => new TranslatedText(['en' => new Text($name)])],
    ], $parent);

    $collection->urls()->create(['slug' => $slug, 'default' => true, 'language_id' => Language::getDefault()->id]);

    return $collection;
}

/**
 * The Products menu panel of a rendered page.
 */
function productsMenu(string $html): string
{
    preg_match('#<ul[^>]*data-menu-categories.*?</ul>\s*</x-ui|<ul[^>]*data-menu-categories.*?</ul>\s*</div>#s', $html, $menu);

    return $menu[0] ?? '';
}

it('lists the categories collection tree, with children, in the Products menu', function (): void {
    $html = $this->get(route('shop'))->getContent();
    $menu = productsMenu($html);

    $paths = [
        'safety-footwear', 'safety-footwear/safety-boots', 'safety-footwear/safety-shoes', 'safety-footwear/safety-trainers',
        'safety-footwear/safety-wellingtons', 'safety-footwear/occupational-footwear', 'safety-footwear/metal-free-metatarsal',
        'non-safety-footwear', 'non-safety-footwear/slippers', 'non-safety-footwear/plimsolls', 'non-safety-footwear/casual-footwear', 'non-safety-footwear/wellingtons',
        'apparel', 'ppe-gear', 'accessories',
    ];

    foreach ($paths as $path) {
        expect($menu)->toContain('href="'.route('category', $path).'"');
    }

    // Children sit under their parent; ranges are not categories.
    preg_match('#href="'.preg_quote(route('category', 'safety-footwear'), '#').'".*?<ul class="mt-1\.5 space-y-1">(.*?)</ul>#s', $menu, $children);

    expect($children[1])->toContain(route('category', 'safety-footwear/safety-boots'))
        ->and($children[1])->toContain('Safety Trainers')
        ->and($menu)->not->toContain('href="'.route('category', 'clearance').'"');
});

it('puts the same categories in the drawer, the footer and the products grid', function (): void {
    $html = $this->get(route('shop'))->getContent();

    // The drawer's lists and the grid's subcategory lists are nested, so each runs to the end of its container.
    foreach (['data-drawer-categories' => '</nav>', 'data-footer-categories' => '</ul>', 'data-category-grid' => '</main>'] as $marker => $end) {
        preg_match('#'.$marker.'.*?'.preg_quote($end, '#').'#s', $html, $list);

        foreach (app(CategoryMenu::class)->tree() as $category) {
            expect($list[0] ?? '')->toContain('href="'.$category['url'].'"', $marker);
        }
    }
});

it('updates when a collection is added, renamed, moved or deleted', function (): void {
    // Warm the cache first, so what follows proves it was cleared.
    expect(productsMenu($this->get(route('shop'))->getContent()))->not->toContain('Test Hi-Vis');

    $added = addCategory('Test Hi-Vis', 'test-hi-vis');

    expect(productsMenu($this->get(route('shop'))->getContent()))->toContain('Test Hi-Vis')
        ->and($this->get(route('home'))->getContent())->toContain('href="'.route('category', 'test-hi-vis').'"');

    // A child appears under its parent.
    $child = addCategory('Test Jackets', 'test-jackets', $added);

    expect(productsMenu($this->get(route('shop'))->getContent()))->toContain('Test Jackets');

    // A rename.
    $added->update(['attribute_data' => ['name' => new TranslatedText(['en' => new Text('Test Visibility')])]]);

    expect(productsMenu($this->get(route('shop'))->getContent()))->toContain('Test Visibility')->not->toContain('Test Hi-Vis');

    // A change to the URL slug alone.
    $added->defaultUrl->update(['slug' => 'test-visibility']);

    expect(productsMenu($this->get(route('shop'))->getContent()))->toContain(route('category', 'test-visibility'));

    // A deletion.
    $child->delete();
    $added->delete();

    expect(productsMenu($this->get(route('shop'))->getContent()))->not->toContain('Test Visibility')->not->toContain('Test Jackets');
});

it('does not list a collection from another group', function (): void {
    addCategory('Spring Offers', 'spring-offers', group: 'ranges');

    expect(productsMenu($this->get(route('shop'))->getContent()))->not->toContain('Spring Offers');
});

it('builds the menu once and then serves it from the cache', function (): void {
    CategoryMenu::forget();
    $menu = app(CategoryMenu::class);

    DB::enableQueryLog();
    $menu->tree();
    $first = count(DB::getQueryLog());
    DB::flushQueryLog();

    $again = $menu->tree();

    expect($first)->toBeGreaterThan(0)
        ->and(DB::getQueryLog())->toBe([])
        ->and($again)->not->toBeEmpty();

    DB::disableQueryLog();
});

it('gives the mobile drawer the dialog and disclosure attributes screen readers need', function (): void {
    $html = $this->get(route('shop'))->getContent();

    // The button that opens it.
    preg_match('#<button[^>]*aria-controls="mobile-menu"[^>]*>#s', $html, $open);

    expect($open[0])->toContain('aria-label="Open menu"')
        ->and($open[0])->toContain('aria-expanded="false"')
        ->and($open[0])->toContain('x-bind:aria-expanded="drawer.toString()"');

    // The drawer is a modal dialog, labelled, with focus trapped while it is open.
    preg_match('#<div\s+id="mobile-menu"[^>]*>#s', $html, $drawer);

    expect($drawer[0])->toContain('role="dialog"')
        ->and($drawer[0])->toContain('aria-modal="true"')
        ->and($drawer[0])->toContain('aria-label="Menu"')
        ->and($drawer[0])->toContain('x-trap.noscroll="drawer"');

    // Esc closes it and, for the Products menu, returns focus to its button.
    expect($html)->toContain('x-on:keydown.escape.window="if (mega) { mega = false; $refs.productsButton.focus(); } drawer = false;"')
        ->and($html)->toContain('aria-label="Close menu"')
        ->and($html)->toContain('<nav aria-label="Mobile"');

    // Every nested disclosure button controls a list that exists, and starts collapsed.
    preg_match_all('#<button[^>]*aria-controls="(drawer-category-\d+)"[^>]*>#s', $html, $buttons);

    expect($buttons[1])->not->toBeEmpty();

    foreach ($buttons[0] as $index => $button) {
        expect($button)->toContain('aria-expanded="false"')
            ->and($button)->toContain('x-bind:aria-expanded="open.toString()"')
            ->and($html)->toContain('id="'.$buttons[1][$index].'"');
    }
});

it('gives the desktop Products button the same disclosure attributes', function (): void {
    $html = $this->get(route('shop'))->getContent();

    preg_match('#<button[^>]*data-products-button[^>]*>#s', $html, $button);

    expect($button[0])->toContain('aria-controls="products-menu"')
        ->and($button[0])->toContain('aria-expanded="false"')
        ->and($button[0])->toContain('x-ref="productsButton"')
        ->and($html)->toContain('id="products-menu"')
        ->and($html)->toContain('x-on:click.outside="mega = false"');
});
