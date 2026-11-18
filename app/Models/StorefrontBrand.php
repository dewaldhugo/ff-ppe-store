<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Lunar\Models\Brand;

/**
 * A brand as the storefront presents it on /brands: one slug, logo and tile
 * colour over one or more Lunar brands (members).
 */
final class StorefrontBrand extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsToMany<Brand, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'storefront_brand_members', 'storefront_brand_id', 'brand_id');
    }
}
