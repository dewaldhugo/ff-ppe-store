<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A vendor's offer for one Lunar variant, in the vendor's own wording.
 */
final class VendorOffer extends Model
{
    protected $table = 'vendor_offers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'integer',
            'stock' => 'integer',
            'sellable' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
