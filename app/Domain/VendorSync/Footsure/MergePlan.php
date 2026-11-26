<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

/**
 * What a merge would do to the database today. Read only.
 */
final readonly class MergePlan
{
    /**
     * @param  list<MergeGroup>  $groups  Lines still on several Lunar products.
     * @param  list<array{ids: list<string>, name: string, reason: string}>  $kept  Lines with the same brand and name that are left apart, and why.
     * @param  int  $alreadyMerged  Lines the detector found that are already one product.
     * @param  int  $products  ProductIDs read from the stored payloads.
     */
    public function __construct(
        public array $groups,
        public array $kept,
        public int $alreadyMerged,
        public int $products,
    ) {}

    /** @return list<MergeGroup> */
    public function mergeable(): array
    {
        return array_values(array_filter($this->groups, fn (MergeGroup $group): bool => $group->skipReason === null));
    }

    public function productsRemoved(): int
    {
        return array_sum(array_map(fn (MergeGroup $group): int => count($group->others), $this->mergeable()));
    }

    /**
     * How many lines were left apart, by the first fact that differs.
     *
     * @return array<string, int>
     */
    public function keptByReason(): array
    {
        $counts = [];

        foreach ($this->kept as $entry) {
            $reason = match (true) {
                str_starts_with($entry['reason'], 'differs in ') => 'differs in '.implode(', ', array_map(fn (string $part): string => trim(explode(' (', $part)[0]), explode(', ', substr($entry['reason'], 11)))),
                str_contains($entry['reason'], 'sold by more than one') => 'the same colour and size is sold by more than one ProductID',
                default => $entry['reason'],
            };

            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }
}
