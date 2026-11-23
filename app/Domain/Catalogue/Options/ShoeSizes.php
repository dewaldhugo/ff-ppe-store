<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Options;

/**
 * The `shoe_size` option values of the data contract: how a size is named,
 * and how it sorts.
 *
 * Labels follow the size scale, never the VAT rate. A row whose EU size is
 * below 33 is on the infant scale and is named `Kids {UK size}` (`Kids 6`).
 * Every other row uses the plain UK size (`1`, `2`, `6`): junior and adult
 * sizes from UK 1 upward are one physical scale.
 *
 * Values are shared across products and vendors, so a value's sort cannot
 * depend on the row that happens to create it. It is the canonical EU size
 * of the value, from the tables below: `Kids 6` is 23, `1` is 33, `6` is 39.
 * Sizes between table entries are interpolated and sizes beyond them
 * extrapolated, so any size sorts in the right place. Only the order matters.
 */
final class ShoeSizes
{
    /** An EU size below this is on the infant scale. */
    public const float INFANT_BELOW_EU = 33.0;

    private const string KIDS_PREFIX = 'Kids ';

    /**
     * UK size => EU size, junior and adult scale. Safety Jogger's published
     * chart, with UK 1 and 2 from the Footsure feed.
     *
     * @var array<string, float>
     */
    private const array STANDARD = [
        '1' => 33, '2' => 34, '3' => 35, '3.5' => 36, '4' => 37, '5' => 38, '6' => 39, '6.5' => 40,
        '7' => 41, '8' => 42, '9' => 43, '10' => 44, '10.5' => 45, '11' => 46, '12' => 47, '13' => 48,
        '13.5' => 49, '14' => 50,
    ];

    /**
     * UK size => EU size, infant scale, from the Footsure feed.
     *
     * @var array<string, float>
     */
    private const array INFANT = [
        '6' => 23, '7' => 24, '8' => 26, '9' => 27, '10' => 28, '11' => 29.5, '12' => 31, '13' => 32,
    ];

    /**
     * The option value for a UK size, from the row's EU size when it has one.
     */
    public static function name(string $uk, ?float $eu): string
    {
        return $eu !== null && $eu < self::INFANT_BELOW_EU ? self::KIDS_PREFIX.$uk : $uk;
    }

    /**
     * The UK size for an EU size on the junior and adult scale (`36` is `3.5`, `43` is `9`), or null when the chart has
     * no entry for it, or the EU size is on the infant scale (where the UK size alone is ambiguous).
     */
    public static function ukFor(string|float $eu): ?string
    {
        if (! is_numeric($eu)) {
            return null;
        }

        foreach (self::STANDARD as $uk => $size) {
            if ((float) $size === (float) $eu) {
                return (string) $uk;
            }
        }

        return null;
    }

    public static function isInfant(string $name): bool
    {
        return str_starts_with($name, self::KIDS_PREFIX);
    }

    /**
     * The sort value of an option value name (`Kids 6`, `10`, `3/3.5`).
     */
    public static function sort(string $name): float
    {
        $infant = self::isInfant($name);
        $uk = $infant ? substr($name, strlen(self::KIDS_PREFIX)) : $name;
        // A jump size sorts by its first part.
        $uk = explode('/', $uk)[0];

        if (! is_numeric($uk)) {
            return 0.0;
        }

        return self::lookup($infant ? self::INFANT : self::STANDARD, (float) $uk);
    }

    /**
     * Exact entry, else a straight line between the neighbours, else one EU
     * size per UK size beyond the ends.
     *
     * @param  array<string, float|int>  $table
     */
    private static function lookup(array $table, float $uk): float
    {
        // A list of pairs: float array keys would be truncated to integers.
        $points = [];

        foreach ($table as $key => $eu) {
            $points[] = [(float) $key, (float) $eu];
        }

        usort($points, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        [$firstUk, $firstEu] = $points[0];

        if ($uk <= $firstUk) {
            return $firstEu - ($firstUk - $uk);
        }

        [$lastUk, $lastEu] = $points[count($points) - 1];

        if ($uk >= $lastUk) {
            return $lastEu + ($uk - $lastUk);
        }

        for ($i = 1, $count = count($points); $i < $count; $i++) {
            [$highUk, $highEu] = $points[$i];

            if ($uk <= $highUk) {
                [$lowUk, $lowEu] = $points[$i - 1];

                return $lowEu + ($uk - $lowUk) / ($highUk - $lowUk) * ($highEu - $lowEu);
            }
        }

        return $lastEu;
    }
}
