<?php

declare(strict_types=1);

namespace App\Domain\Compare;

/**
 * The products a visitor has ticked to compare, kept in the session (so a guest can compare too, with no account). A list of
 * product ids in the order they were ticked, never more than MAX. The page and the bar read it; the toggle changes it.
 */
final class Compare
{
    public const int MAX = 4;

    public const string KEY = 'compare';

    /** @return list<int> */
    public function ids(): array
    {
        $ids = session(self::KEY, []);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    public function has(int $productId): bool
    {
        return in_array($productId, $this->ids(), true);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /**
     * Adds the product, or takes it out when it is already there. A fifth product is refused (`full`), not swapped in.
     *
     * @return array{on: bool, full: bool}
     */
    public function toggle(int $productId): array
    {
        $ids = $this->ids();

        if (in_array($productId, $ids, true)) {
            session([self::KEY => array_values(array_diff($ids, [$productId]))]);

            return ['on' => false, 'full' => false];
        }

        if (count($ids) >= self::MAX) {
            return ['on' => false, 'full' => true];
        }

        session([self::KEY => [...$ids, $productId]]);

        return ['on' => true, 'full' => false];
    }

    public function clear(): void
    {
        session()->forget(self::KEY);
    }
}
