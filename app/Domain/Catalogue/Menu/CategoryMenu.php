<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Menu;

use Illuminate\Support\Facades\Cache;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;

/**
 * The `categories` collection group as the storefront menu: top-level
 * collections with their children, in the order set in the admin panel.
 *
 * The one source for the header menu, the mobile drawer, the footer and the
 * category grids. Nothing is hard-coded: a collection added, renamed, moved
 * or deleted in the admin panel appears here. The result is cached, and the
 * cache is cleared by the observers registered in AppServiceProvider
 * whenever a collection, its URL or its group changes.
 */
final class CategoryMenu
{
    public const string CACHE_KEY = 'storefront.category_menu';

    /**
     * @return list<array{name: string, url: string, children: list<array{name: string, url: string}>}>
     */
    public function tree(): array
    {
        // Cleared by observers on every change. The time limit only covers a change
        // that bypasses model events, such as a raw tree rebuild.
        return Cache::remember(self::CACHE_KEY, now()->addHours(6), fn (): array => $this->build());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<array{name: string, url: string, children: list<array{name: string, url: string}>}>
     */
    private function build(): array
    {
        $group = CollectionGroup::query()->where('handle', 'categories')->first();

        if ($group === null) {
            return [];
        }

        $tree = Collection::query()
            ->where('collection_group_id', $group->id)
            ->with('defaultUrl')
            ->defaultOrder()
            ->get()
            ->toTree();

        $item = fn (Collection $collection): array => [
            'name' => (string) $collection->translateAttribute('name'),
            'url' => route('category', $collection->defaultUrl?->slug ?? $collection->id),
        ];

        $menu = [];

        foreach ($tree as $collection) {
            $menu[] = [
                ...$item($collection),
                'children' => $collection->children->map($item)->values()->all(),
            ];
        }

        return $menu;
    }
}
