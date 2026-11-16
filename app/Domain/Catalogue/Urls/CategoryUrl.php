<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use Lunar\Models\Collection;

/**
 * Builds a category link. Every category link in the storefront goes through
 * here, so the path logic exists in one place only. The route is `category`.
 */
final class CategoryUrl
{
    public function __construct(private readonly CollectionPath $paths) {}

    /**
     * @param  array<string, mixed>  $params  query string parameters
     * @return string|null null when the collection has no category path
     */
    public function for(Collection $collection, array $params = []): ?string
    {
        $path = $this->paths->for($collection);

        if ($path === null) {
            return null;
        }

        return route('category', ['path' => (string) CollectionPath::segments($path), ...$params]);
    }

    /**
     * The link for a collection named by its contract handle, e.g. safety-footwear.
     *
     * @param  array<string, mixed>  $params
     */
    public function forHandle(string $handle, array $params = []): ?string
    {
        return $this->for(CatalogueKeys::collection($handle), $params);
    }
}
