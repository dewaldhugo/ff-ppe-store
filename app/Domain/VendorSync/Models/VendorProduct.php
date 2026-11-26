<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Product;

/**
 * Links a Lunar product to the vendor's product and holds sync state.
 *
 * A Lunar product normally has one link per vendor. A product line that
 * Footsure lists under several ProductIDs (one per size band) has one link
 * for each, all pointing at the same Lunar product. The link with the lowest
 * `id` is the primary: the image jobs run for it alone, and the other links
 * only record that their ProductID belongs to the product.
 */
final class VendorProduct extends Model
{
    protected $table = 'vendor_products';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content_hashes' => 'array',
            'draft_reasons' => 'array',
            'related_ids' => 'array',
            'missed_runs' => 'integer',
            'images_synced_at' => 'datetime',
        ];
    }

    /**
     * Only the primary link of each product and vendor: the one with the
     * lowest `id` of the links that point at the same product.
     *
     * @param  Builder<VendorProduct>  $query
     * @return Builder<VendorProduct>
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->whereNotExists(function ($other): void {
            $other->selectRaw('1')
                ->from('vendor_products as other_links')
                ->whereColumn('other_links.vendor_id', 'vendor_products.vendor_id')
                ->whereColumn('other_links.product_id', 'vendor_products.product_id')
                ->whereColumn('other_links.id', '<', 'vendor_products.id');
        });
    }

    /**
     * Every link of the same product and vendor, this one included, lowest `id` first.
     *
     * @return EloquentCollection<int, VendorProduct>
     */
    public function siblings(): EloquentCollection
    {
        return self::query()
            ->where('vendor_id', $this->vendor_id)
            ->where('product_id', $this->product_id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
