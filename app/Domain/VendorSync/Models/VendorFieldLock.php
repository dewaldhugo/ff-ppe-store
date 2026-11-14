<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A product attribute that imports must never overwrite.
 */
final class VendorFieldLock extends Model
{
    protected $table = 'vendor_field_locks';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
