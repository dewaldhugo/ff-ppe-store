<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Display;

/**
 * An ISO 3166-1 alpha-2 code (the contract's `country_of_origin`) as an English country name. An unknown code is shown as it is.
 */
final class CountryName
{
    public static function of(string $code): string
    {
        $code = trim($code);

        if (preg_match('/^[A-Za-z]{2}$/', $code) !== 1) {
            return $code;
        }

        $code = strtoupper($code);

        $name = \Locale::getDisplayRegion('-'.$code, 'en');

        return $name === '' || $name === $code ? $code : $name;
    }
}
