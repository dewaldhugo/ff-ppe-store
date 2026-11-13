<?php

declare(strict_types=1);

namespace App\View\Components\Storefront;

use App\Domain\Pricing\PriceDisplay;
use App\Domain\Pricing\VatDisplayMode;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;

final class Header extends Component
{
    public function __construct(private readonly PriceDisplay $priceDisplay) {}

    public function render(): View
    {
        return view('components.storefront.header', [
            'categories' => $this->categories(),
            'vatMode' => $this->priceDisplay->mode(),
            'vatModes' => VatDisplayMode::cases(),
        ]);
    }

    /**
     * The `categories` collection group as a two-level menu.
     *
     * @return list<array{name: string, url: string, children: list<array{name: string, url: string}>}>
     */
    private function categories(): array
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
