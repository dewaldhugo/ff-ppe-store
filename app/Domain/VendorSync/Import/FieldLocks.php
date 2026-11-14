<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Models\VendorFieldLock;

/**
 * Product attributes an import must never overwrite.
 */
final class FieldLocks
{
    /**
     * @return list<string>
     */
    public function for(int $productId): array
    {
        return VendorFieldLock::query()->where('product_id', $productId)->pluck('field')->all();
    }

    public function lock(int $productId, string $field): void
    {
        VendorFieldLock::query()->firstOrCreate(['product_id' => $productId, 'field' => $field]);
    }

    public function unlock(int $productId, string $field): void
    {
        VendorFieldLock::query()->where('product_id', $productId)->where('field', $field)->delete();
    }
}
