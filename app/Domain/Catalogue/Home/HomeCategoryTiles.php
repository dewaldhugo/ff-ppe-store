<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use Lunar\Models\Collection;
use Lunar\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The tiles under the home hero: the children of the `safety-footwear` category,
 * in admin order. Each shows the first image of the first published product in
 * that collection that has one. Nothing is cached, so a change in the admin
 * panel shows at once.
 */
final class HomeCategoryTiles
{
    public const string PARENT = 'safety-footwear';

    /**
     * @return list<array{name: string, url: string, image: array{src: string, srcset: string, width: int, height: int}|null}>
     */
    public function tiles(): array
    {
        $parent = CatalogueKeys::collection(self::PARENT);

        return $parent->children()
            ->defaultOrder()
            ->with('defaultUrl')
            ->get()
            ->map(fn (Collection $collection): array => [
                'name' => (string) $collection->translateAttribute('name'),
                'url' => route('category', $collection->defaultUrl?->slug ?? $collection->id),
                'image' => $this->image($collection),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{src: string, srcset: string, width: int, height: int}|null
     */
    private function image(Collection $collection): ?array
    {
        $name = (string) config('lunar.media.collection');

        /** @var Product|null $product */
        $product = $collection->products()
            ->where('status', 'published')
            ->whereHas('media', fn ($query) => $query->where('collection_name', $name))
            ->orderByPivot('position')
            ->first();

        /** @var Media|null $media */
        $media = $product?->getMedia($name)->first();

        if ($media === null) {
            return null;
        }

        return [
            'src' => $media->getUrl('medium'),
            'srcset' => $media->getUrl('thumb').' 300w, '.$media->getUrl('medium').' 800w',
            'width' => 800,
            'height' => 800,
        ];
    }
}
