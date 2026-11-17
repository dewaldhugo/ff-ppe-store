<?php

declare(strict_types=1);

use App\Domain\Catalogue\Menu\CategoryMenu;

/**
 * The category grid of a rendered /shop page.
 */
function categoryGrid(string $html): string
{
    preg_match('#data-category-grid.*?</main>#s', $html, $grid);

    return $grid[0] ?? '';
}

it('lays the cards out five to a row on desktop', function (): void {
    $html = $this->get(route('shop'))->assertOk()->getContent();

    preg_match('#<ul[^>]*data-category-grid[^>]*>#', $html, $ul);

    expect($ul[0] ?? '')->toContain('lg:grid-cols-5')->toContain('sm:grid-cols-2')
        ->and(count(app(CategoryMenu::class)->tree()))->toBe(5);
});

it('keeps each card as one link to its category, with no subcategory links inside it', function (): void {
    $grid = categoryGrid($this->get(route('shop'))->assertOk()->getContent());

    preg_match_all('#<div[^>]*data-category-card>.*?</div>#s', $grid, $cards);

    expect($cards[0])->toHaveCount(5);

    foreach (app(CategoryMenu::class)->tree() as $i => $category) {
        expect(substr_count($cards[0][$i], '<a '))->toBe(1)
            ->and($cards[0][$i])->toContain('href="'.$category['url'].'"');
    }
});

it('lists every subcategory as a link below its card', function (): void {
    $grid = categoryGrid($this->get(route('shop'))->assertOk()->getContent());

    $lists = preg_split('#(?=<ul[^>]*data-category-children)#', $grid);
    array_shift($lists);

    $withChildren = array_values(array_filter(app(CategoryMenu::class)->tree(), fn (array $category): bool => $category['children'] !== []));

    expect($lists)->toHaveCount(count($withChildren));

    foreach ($withChildren as $i => $category) {
        $list = substr($lists[$i], 0, (int) strpos($lists[$i], '</ul>'));

        foreach ($category['children'] as $child) {
            expect($list)->toContain('href="'.$child['url'].'"')->toContain(e($child['name']));
        }

        expect(substr_count($list, '<a '))->toBe(count($category['children']));
    }
});
