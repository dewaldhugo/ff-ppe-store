<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls;

use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;

/**
 * The one place a category's storefront path is worked out: the URL slugs of
 * its ancestors plus its own, under the shop prefix. Paths are root-relative
 * with a leading slash, e.g. /shop/safety-footwear/safety-boots.
 *
 * Only collections in the `categories` group have a path. The whole group is
 * loaded in two queries and held for the request, so a menu or a tile row
 * never runs a query per collection. Call flush() after a change.
 */
final class CollectionPath
{
    /** The only definition of the shop prefix. */
    public const string PREFIX = 'shop';

    public const string GROUP_HANDLE = 'categories';

    /** @var array<int, string>|null collection id => path */
    private ?array $paths = null;

    private ?int $groupId = null;

    private bool $groupLoaded = false;

    /** The shop index, /shop. */
    public static function index(): string
    {
        return '/'.self::PREFIX;
    }

    /** The path for the segments below the prefix, e.g. safety-footwear/safety-boots. */
    public static function fromSegments(string $segments): string
    {
        return self::index().'/'.$segments;
    }

    /** The part of a full path below the prefix, or null when it is not under it. */
    public static function segments(string $path): ?string
    {
        $start = self::index().'/';

        return str_starts_with($path, $start) ? substr($path, strlen($start)) : null;
    }

    public function groupId(): ?int
    {
        if (! $this->groupLoaded) {
            $id = CollectionGroup::query()->where('handle', self::GROUP_HANDLE)->value('id');
            $this->groupId = $id === null ? null : (int) $id;
            $this->groupLoaded = true;
        }

        return $this->groupId;
    }

    public function isCategory(Collection $collection): bool
    {
        $groupId = $this->groupId();

        return $groupId !== null && (int) $collection->collection_group_id === $groupId;
    }

    /** The collection's path, or null when it is not a category or has no default URL. */
    public function for(Collection|int $collection): ?string
    {
        return $this->all()[$collection instanceof Collection ? $collection->id : $collection] ?? null;
    }

    /**
     * Every category's path.
     *
     * @return array<int, string> collection id => path
     */
    public function all(): array
    {
        if ($this->paths !== null) {
            return $this->paths;
        }

        $groupId = $this->groupId();

        if ($groupId === null) {
            return $this->paths = [];
        }

        $collections = Collection::query()
            ->where('collection_group_id', $groupId)
            ->with('defaultUrl')
            ->get(['id', 'parent_id'])
            ->keyBy('id');

        $slugs = $collections->map(fn (Collection $collection): ?string => $collection->defaultUrl?->slug);

        $paths = [];

        foreach ($collections as $id => $collection) {
            $segments = [];
            $node = $collection;
            $complete = true;

            // The depth cap only guards against a corrupt tree.
            for ($depth = 0; $node !== null && $depth < 32; $depth++) {
                $slug = $slugs[$node->id] ?? null;

                if ($slug === null || $slug === '') {
                    $complete = false;
                    break;
                }

                array_unshift($segments, $slug);
                $node = $node->parent_id === null ? null : $collections->get($node->parent_id);
            }

            if ($complete) {
                $paths[(int) $id] = self::fromSegments(implode('/', $segments));
            }
        }

        return $this->paths = $paths;
    }

    public function flush(): void
    {
        $this->paths = null;
        $this->groupLoaded = false;
        $this->groupId = null;
    }
}
