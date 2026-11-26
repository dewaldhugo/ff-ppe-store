<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

/**
 * What the split detector found in one feed.
 *
 * - `groups`: product lines the vendor split into several ProductIDs by size
 *   band, each a list of vendor product IDs in ascending numeric order (the
 *   first is the primary).
 * - `kept`: product lines that share a brand and name but were NOT merged,
 *   each with the reason, so a person can look at them.
 *
 * @phpstan-type Kept array{ids: list<string>, name: string, reason: string}
 */
final readonly class SplitDetection
{
    /**
     * @param  list<list<string>>  $groups
     * @param  list<array{ids: list<string>, name: string, reason: string}>  $kept
     */
    public function __construct(
        public array $groups,
        public array $kept,
    ) {}

    /**
     * Each member ID to the primary ID of its group.
     *
     * @return array<string, string>
     */
    public function primaries(): array
    {
        $map = [];

        foreach ($this->groups as $group) {
            foreach ($group as $id) {
                $map[$id] = $group[0];
            }
        }

        return $map;
    }

    /**
     * The number of products a merge removes: every member but the primary.
     */
    public function removed(): int
    {
        return array_sum(array_map(fn (array $group): int => count($group) - 1, $this->groups));
    }
}
