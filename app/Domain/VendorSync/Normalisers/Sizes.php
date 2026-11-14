<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Normalisers;

/**
 * UK shoe sizes as the `shoe_size` option values of the data contract.
 */
final class Sizes
{
    /** Kids sizes below this are the larger sizes of the children's scale (1 to 6.5). */
    private const float KIDS_SCALE_RESTART = 7.0;

    /**
     * A vendor UK size as the contract string: no trailing `.0`
     * (`10`, not `10.0`). Null when the value is not a size.
     */
    public static function uk(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if (preg_match('/^\d{1,2}(\.\d+)?$/', $raw) !== 1) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $raw, 1, '.', ''), '0'), '.');
    }

    /**
     * Convert a vendor EU size through a size chart. Returns the UK value
     * (`10`, or `3/3.5` for a jump size), or null when the chart has no
     * entry. Never guess a conversion.
     *
     * @param  array<array-key, string>  $chart
     */
    public static function fromChart(array $chart, ?string $eu): ?string
    {
        $eu = preg_replace('/\s+/', '', (string) $eu);

        return $eu === '' || $eu === null ? null : ($chart[$eu] ?? null);
    }

    /**
     * Numeric sort value of an adult size. A jump size sorts by its first
     * part.
     */
    public static function sort(string $uk): float
    {
        return (float) explode('/', $uk)[0];
    }

    /**
     * A children's size never shares an option value with an adult size.
     */
    public static function kidsName(string $uk): string
    {
        return 'Kids '.$uk;
    }

    /**
     * Below every adult size, following the UK children's scale where 7 to
     * 13.5 come before 1 to 6.5.
     */
    public static function kidsSort(string $uk): float
    {
        $size = self::sort($uk);

        return $size >= self::KIDS_SCALE_RESTART ? $size - 100 : $size - 80;
    }
}
