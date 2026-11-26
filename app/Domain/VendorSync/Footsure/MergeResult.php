<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

final readonly class MergeResult
{
    /**
     * @param  array<string, int>  $counts
     */
    private function __construct(
        public MergeGroup $group,
        public bool $merged,
        public array $counts,
        public ?string $note,
    ) {}

    /**
     * @param  array<string, int>  $counts
     */
    public static function merged(MergeGroup $group, array $counts, ?string $warning): self
    {
        return new self($group, true, $counts, $warning);
    }

    public static function skipped(MergeGroup $group, string $reason): self
    {
        return new self($group, false, [], $reason);
    }
}
