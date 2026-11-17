<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Options;

/**
 * The `garment_size` option values: clothing, gloves and PPE that is not
 * sized on the shoe scale. Vendor wording is kept wherever it is not one of
 * the alphabetic sizes below (a waist or chest measurement, for example, is
 * stored exactly as the vendor gives it, inches mark included).
 */
final class GarmentSizes
{
    /** Vendor spelling, lower case => the contract's short form. */
    private const array ALPHA = [
        'xxs' => 'XXS', 'xxsmall' => 'XXS', 'xxs.' => 'XXS',
        'xs' => 'XS', 'xsl' => 'XS', 'xsmall' => 'XS', 'extra small' => 'XS',
        's' => 'S', 'sml' => 'S', 'small' => 'S',
        'm' => 'M', 'med' => 'M', 'medium' => 'M',
        'l' => 'L', 'lge' => 'L', 'large' => 'L',
        'xl' => 'XL', 'xlarge' => 'XL', 'extra large' => 'XL',
        'xxl' => 'XXL', '2xl' => 'XXL', 'xxlarge' => 'XXL',
        '3xl' => '3XL', '4xl' => '4XL', '5xl' => '5XL', '6xl' => '6XL',
    ];

    /** Vendor spelling, lower case, that means the item has no sizes. */
    private const array ONE_SIZE = ['itm', 'item', 'itm.', 'os', 'one size', 'onesize', 'uni', 'unisize', 'n/a'];

    public const string ONE_SIZE_LABEL = 'One Size';

    /** Display order for the alphabetic sizes. Anything else sorts by its own rules (see sort()). */
    private const array ALPHA_ORDER = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '5XL', '6XL'];

    /**
     * The option value for a vendor size string. Null when the vendor gave
     * nothing at all usable (every candidate column was blank): the caller
     * falls back to One Size rather than guessing a real size.
     */
    public static function name(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $lower = mb_strtolower($raw);

        if (isset(self::ALPHA[$lower])) {
            return self::ALPHA[$lower];
        }

        if (in_array($lower, self::ONE_SIZE, true)) {
            return self::ONE_SIZE_LABEL;
        }

        // Kept exactly as the vendor gives it: a waist or chest measurement
        // (`34"`, `42`), a glove size (`10`), or wording this list does not
        // yet know, rather than dropping a real size silently.
        return $raw;
    }

    /**
     * Display order: One Size first, then the alphabetic scale, then every
     * numeric value (inches, centimetres or bare numbers) in ascending
     * order, then anything else alphabetically.
     */
    public static function sort(string $name): float
    {
        if ($name === self::ONE_SIZE_LABEL) {
            return -1.0;
        }

        $alpha = array_search($name, self::ALPHA_ORDER, true);

        if ($alpha !== false) {
            return (float) $alpha;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)"?$/', $name, $matches) === 1) {
            return 100.0 + (float) $matches[1];
        }

        // Unrecognised wording sorts after every known size, in the order it
        // is first seen (position is not derivable from the string alone;
        // the writer falls back to insertion order for these).
        return 10000.0;
    }
}
