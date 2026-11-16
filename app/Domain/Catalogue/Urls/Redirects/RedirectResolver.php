<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls\Redirects;

use App\Models\UrlRedirect;
use Illuminate\Support\Facades\Log;

/**
 * Finds where a retired path now lives. The recorder keeps chains out of the
 * table, but this still follows a bounded number of hops, so a damaged table
 * can never loop a visitor. Paths are matched exactly, never by collation.
 */
final class RedirectResolver
{
    private const int MAX_HOPS = 10;

    public function resolve(string $path): ?string
    {
        $seen = [$path => true];
        $current = $path;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $row = UrlRedirect::query()->where('old_path', $current)->first();

            if ($row === null || $row->old_path !== $current) {
                return $current === $path ? null : $current;
            }

            if (isset($seen[$row->new_path])) {
                Log::warning('Redirect loop found while resolving a path.', ['path' => $path]);

                return null;
            }

            $seen[$row->new_path] = true;
            $current = $row->new_path;
        }

        Log::warning('Redirect chain too long while resolving a path.', ['path' => $path]);

        return null;
    }
}
