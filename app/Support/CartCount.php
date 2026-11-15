<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The number of items in the visitor's cart, for the header badge.
 *
 * There is no cart yet, so this is 0. The cart phase replaces the body;
 * the header already reads it from here.
 */
final class CartCount
{
    public static function get(): int
    {
        return 0;
    }
}
