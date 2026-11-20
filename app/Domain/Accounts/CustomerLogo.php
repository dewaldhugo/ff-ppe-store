<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Support\Facades\Storage;
use Lunar\Models\Customer;

/**
 * The logo of a member's company, set by staff in the admin panel (Customers, Edit) and shown on
 * My Account. It is the path of an image on the public disk, kept in the Lunar customer's `meta`
 * under `logo`, like the industry, so no Lunar table changes. Customers cannot change it.
 *
 * Only a path inside DIRECTORY is accepted, so a crafted value can never point at, or delete, any
 * other file. Replacing or removing a logo deletes the old file.
 */
final class CustomerLogo
{
    public const string DISK = 'public';

    public const string DIRECTORY = 'customer-logos';

    /** The stored path of the logo, or null when there is none. */
    public function path(Customer $customer): ?string
    {
        $path = $customer->meta['logo'] ?? null;

        return is_string($path) && $this->isStoredPath($path) ? $path : null;
    }

    /** The public URL of the logo, or null when there is none or its file has gone. */
    public function url(?Customer $customer): ?string
    {
        $path = $customer instanceof Customer ? $this->path($customer) : null;

        if ($path === null || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->url($path);
    }

    /** Sets the logo (a path from the upload field), or clears it with null or an empty string. */
    public function set(Customer $customer, ?string $path): void
    {
        $path = filled($path) && $this->isStoredPath((string) $path) ? $path : null;
        $old = $this->path($customer);

        if ($path === $old) {
            return;
        }

        $meta = (array) $customer->meta;

        if ($path === null) {
            unset($meta['logo']);
        } else {
            $meta['logo'] = $path;
        }

        $customer->meta = $meta;
        // Quietly: meta is not searched, so the customer index has nothing to learn.
        $customer->saveQuietly();

        if ($old !== null) {
            Storage::disk(self::DISK)->delete($old);
        }
    }

    private function isStoredPath(string $path): bool
    {
        return str_starts_with($path, self::DIRECTORY.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_contains($path, '//');
    }
}
