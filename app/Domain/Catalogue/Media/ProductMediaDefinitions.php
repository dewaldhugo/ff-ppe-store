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
 * large (1200, the widest an image is stored). "small" is kept because Lunar's admin panel requests it.
 */
final class ProductMediaDefinitions implements MediaDefinitionsInterface
{
    public const string DISK = 'products';

    /** The product's documents (data contract, Media): English product sheets, one per colour. No conversions. */
    public const string DOCUMENTS = 'documents';

    /** @var array<string, int> */
    private const array CONVERSIONS = [
        'thumb' => 300,
        'medium' => 800,
        'large' => 1200,
    ];

    public function registerMediaConversions(HasMedia $model, ?Media $media = null): void
    {
        // A document (a product sheet PDF) is stored as it is, never converted.
        if ($media !== null && $media->collection_name === self::DOCUMENTS) {
            return;
        }

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

        $model->addMediaCollection(self::DOCUMENTS)->useDisk(self::DISK);

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
        return ['images' => 'Images', self::DOCUMENTS => 'Documents'];
    }

    /**
     * @return array<string, string>
     */
    public function getMediaCollectionDescriptions(): array
    {
        return ['images' => ''];
    }
}
