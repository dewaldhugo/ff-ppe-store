<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The payload of one vendor product exactly as fetched, with its hash.
 */
final class VendorRawRecord extends Model
{
    protected $table = 'vendor_raw_records';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
