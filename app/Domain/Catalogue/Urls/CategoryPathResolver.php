<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls;

use App\Domain\Catalogue\Urls\Redirects\RedirectResolver;

/**
 * Turns the part of a /shop URL below the prefix into a collection, a
 * redirect, or nothing (a 404).
 *
 * The full path is always checked against the collection's real ancestry, so
 * two parents may each have a child with the same slug. Paths are compared
 * exactly in PHP, never through the database collation: a request that differs
 * only by case is redirected to the canonical lowercase path.
 */
final class CategoryPathResolver
{
    public function __construct(
        private readonly CollectionPath $paths,
        private readonly RedirectResolver $redirects,
    ) {}

    public function resolve(string $segments): ?CategoryMatch
    {
        $requested = CollectionPath::fromSegments($segments);
        $live = $this->paths->all();
        $ids = array_flip($live);

        // 1. The path is exactly right.
        if (isset($ids[$requested])) {
            return CategoryMatch::found($ids[$requested]);
        }

        // 2. The path was retired by a rename or a move.
        $target = $this->redirects->resolve($requested);

        if ($target !== null && isset($ids[$target])) {
            return CategoryMatch::redirect($target);
        }

        // 3. Right collection, wrong spelling or wrong ancestry.
        $candidates = array_filter(
            $live,
            fn (string $path): bool => strtolower($this->lastSegment($path)) === strtolower($this->lastSegment($requested)),
        );

        foreach ($candidates as $path) {
            if (strtolower($path) === strtolower($requested)) {
                return CategoryMatch::redirect($path);
            }
        }

        // The last segment alone is only trusted when it names one collection.
        if (count($candidates) === 1) {
            return CategoryMatch::redirect(reset($candidates));
        }

        return null;
    }

    private function lastSegment(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? $path : substr($path, $position + 1);
    }
}
