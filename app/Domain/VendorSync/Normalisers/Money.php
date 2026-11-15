<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Normalisers;

use InvalidArgumentException;

/**
 * Integer pence arithmetic for vendor prices. Rounds half up to the penny.
 * Floats only appear at the boundary, where a vendor's JSON number is
 * turned into pence once.
 */
final class Money
{
    /**
     * A vendor decimal amount in pounds ("45.93", 45.93, "9.99") to pence.
     * Null for blank, negative or non-numeric input.
     */
    public static function toPence(mixed $value): ?int
    {
        if (is_float($value) || is_int($value)) {
            $value = number_format((float) $value, 4, '.', '');
        }

        if (! is_string($value) || preg_match('/^\s*(\d+)(?:\.(\d+))?\s*$/', $value, $match) !== 1) {
            return null;
        }

        $fraction = str_pad(substr($match[2] ?? '', 0, 3), 3, '0');
        $thousandths = (int) $match[1] * 1000 + (int) $fraction;

        return intdiv($thousandths + 5, 10);
    }

    /**
     * Remove VAT from a VAT-inclusive amount. The rate is in basis points
     * (2000 = 20%).
     */
    public static function exVat(int $incVatPence, int $vatBasisPoints): int
    {
        return self::divideRounded($incVatPence * 10000, 10000 + $vatBasisPoints);
    }

    /**
     * Multiply by a decimal string such as "1.5" without using floats.
     */
    public static function multiply(int $pence, string $factor): int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', trim($factor), $match) !== 1) {
            throw new InvalidArgumentException("Not a decimal factor: {$factor}");
        }

        $decimals = $match[2] ?? '';
        $numerator = (int) ($match[1].$decimals);
        $denominator = 10 ** strlen($decimals);

        return self::divideRounded($pence * $numerator, $denominator);
    }

    private static function divideRounded(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
