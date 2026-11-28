<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Product;

/**
 * A campaign of the home page's Product Spotlight, added in the admin (Spotlight banners): the banner picture (on the public disk)
 * and the product it is about. App\Domain\Catalogue\Home\HomeSpotlight picks one active campaign at random on every page load.
 * Deleting a campaign deletes its picture, and so does replacing it.
 *
 * @property int $id
 * @property int $product_id
 * @property string $image Path on the public disk.
 * @property ?string $alt
 * @property bool $active
 * @property int $position
 */
final class SpotlightBanner extends Model
{
    public const string DISK = 'public';

    public const string DIRECTORY = 'spotlights';

    protected $fillable = ['product_id', 'image', 'alt', 'active', 'position'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(function (self $banner): void {
            if (filled($banner->image)) {
                Storage::disk(self::DISK)->delete($banner->image);
            }
        });

        self::updating(function (self $banner): void {
            $old = $banner->getOriginal('image');

            if (filled($old) && $old !== $banner->image) {
                Storage::disk(self::DISK)->delete($old);
            }
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function imageUrl(): string
    {
        return Storage::disk(self::DISK)->url($this->image);
    }
}
