<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls\Redirects;

use App\Domain\Catalogue\Urls\CollectionPath;
use Lunar\Models\Collection;
use Lunar\Models\Url;

/**
 * Keeps redirects in step with category URLs. Registered on the Collection
 * and Url models, so a rename or a move made in the admin panel (or anywhere
 * else that saves a model) is caught as it happens.
 *
 * It sits on a hot path: the ingestion module saves thousands of product Url
 * rows. Anything that is not a category collection returns before a query.
 */
final class CollectionRedirectObserver
{
    public function __construct(
        private readonly CollectionPath $paths,
        private readonly CollectionRedirectSync $sync,
    ) {}

    public function collectionChanged(Collection $collection): void
    {
        if (! $this->paths->isCategory($collection)) {
            return;
        }

        $this->sync->sync();
    }

    public function urlChanged(Url $url): void
    {
        // The element type is on the row itself, so a product Url costs nothing.
        if ($url->element_type !== Collection::morphName()) {
            return;
        }

        $groupId = $this->paths->groupId();

        if ($groupId === null) {
            return;
        }

        $isCategory = Collection::query()
            ->whereKey($url->element_id)
            ->where('collection_group_id', $groupId)
            ->exists();

        if ($isCategory) {
            $this->sync->sync();
        }
    }
}
