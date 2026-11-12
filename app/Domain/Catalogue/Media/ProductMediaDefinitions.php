<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Media;

use Lunar\Base\MediaDefinitionsInterface;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Product images as the data contract defines them: collection "images" on
 * the "products" disk, with WebP conversions thumb (300), medium (800) and
 * large (1600). "small" is kept because Lunar's admin panel requests it.
 */
final class ProductMediaDefinitions implements MediaDefinitionsInterface
{
    public const string DISK = 'products';

    /** @var array<string, int> */
    private const array CONVERSIONS = [
        'thumb' => 300,
        'medium' => 800,
        'large' => 1600,
    ];

    public function registerMediaConversions(HasMedia $model, ?Media $media = null): void
    {
        $model->addMediaConversion('small')
            ->fit(Fit::Contain, 300, 300)
            ->format('webp')
            ->nonQueued();
    }

    public function registerMediaCollections(HasMedia $model): void
    {
        // Reset to avoid registering the collection twice.
        $model->mediaCollections = [];

        $collection = $model
            ->addMediaCollection(config('lunar.media.collection'))
            ->useDisk(self::DISK);

        if ($fallbackUrl = config('lunar.media.fallback.url')) {
            $collection->useFallbackUrl($fallbackUrl);
        }

        if ($fallbackPath = config('lunar.media.fallback.path')) {
            $collection->useFallbackPath($fallbackPath);
        }

        $collection->registerMediaConversions(function () use ($model): void {
            foreach (self::CONVERSIONS as $name => $size) {
                $model->addMediaConversion($name)
                    ->fit(Fit::Max, $size, $size)
                    ->format('webp');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function getMediaCollectionTitles(): array
    {
        return ['images' => 'Images'];
    }

    /**
     * @return array<string, string>
     */
    public function getMediaCollectionDescriptions(): array
    {
        return ['images' => ''];
    }
}
