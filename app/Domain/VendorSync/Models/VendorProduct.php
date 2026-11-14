<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Product;

/**
 * Links a Lunar product to the vendor's product and holds sync state.
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
