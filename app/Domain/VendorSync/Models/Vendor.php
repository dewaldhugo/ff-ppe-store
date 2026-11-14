<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A supplier whose catalogue is imported (SJ, FS).
 */
final class Vendor extends Model
{
    protected $table = 'vendors';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
