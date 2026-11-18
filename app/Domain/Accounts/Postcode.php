<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

/**
 * A UK postcode, format only. Stored upper case with one space before the last three characters.
 */
final class Postcode
{
    public const string PATTERN = '/^(?:GIR 0AA|[A-PR-UWYZ](?:[0-9]{1,2}|[A-HK-Y][0-9]{1,2}|[0-9][A-HJKPS-UW]|[A-HK-Y][0-9][ABEHMNPRV-Y]) [0-9][ABD-HJLNP-UW-Z]{2})$/';

    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/\s+/', '', $value));

        if ($clean === '') {
            return null;
        }

        // A value too short to split is left as it is, for the format rule to refuse.
        return strlen($clean) > 3 ? substr($clean, 0, -3).' '.substr($clean, -3) : $clean;
    }
}
