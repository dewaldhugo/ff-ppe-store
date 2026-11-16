<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Normalisers;

use InvalidArgumentException;

/**
 * The pricing settings of one vendor, resolved from config.
 *
 * The markup is the global default (`vendorsync.markup`) unless the vendor
 * overrides it (`vendorsync.vendors.{CODE}.markup`). Both are validated every
 * time a mapper is resolved, so a typo in `.env` stops the run before any
 * price is calculated.
 */
final class PricingRules
{
    public const float MIN_MARKUP = 1.0;

    public const float MAX_MARKUP = 5.0;

    /**
     * Bump this whenever the pricing rules change in a way that alters
     * stored prices for an unchanged vendor payload. It is part of the
     * fingerprint, so the next run reprocesses every product once.
     *
     * 1: price capped at the RRP ex VAT.
     * 2: no cap, GBP only, cost_at_or_above_rrp draft reason.
     */
    public const int RULES_VERSION = 2;

    /**
     * The markup for a vendor, as a decimal string. Throws when the global
     * default or the vendor's override is not a number from 1.0 to 5.
     */
    public static function markup(string $vendorCode): string
    {
        $default = self::validated(config('vendorsync.markup'), 'vendorsync.markup (VENDORSYNC_MARKUP)');
        $override = config("vendorsync.vendors.{$vendorCode}.markup");

        if ($override === null || $override === '') {
            return $default;
        }

        return self::validated($override, "vendorsync.vendors.{$vendorCode}.markup (VENDORSYNC_{$vendorCode}_MARKUP)");
    }

    /**
     * What the stored prices of a vendor depend on besides its payload: the
     * rule version and its markup. The importer folds it into each product's
     * hash, so a change to either reprices existing products on the next run
     * (the guard still watches cost, so the markup alone never holds anything).
     */
    public static function fingerprint(string $vendorCode): string
    {
        return hash('sha256', 'pricing-v'.self::RULES_VERSION.'|markup='.self::markup($vendorCode).'|currency=GBP');
    }

    /**
     * A calculator of its own for this vendor. Mappers never share one.
     */
    public static function calculator(string $vendorCode): PriceCalculator
    {
        return new PriceCalculator(self::markup($vendorCode));
    }

    private static function validated(mixed $value, string $setting): string
    {
        $markup = is_int($value) || is_float($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        if (preg_match('/^\d+(?:\.\d+)?$/', $markup) !== 1) {
            throw new InvalidArgumentException("Invalid markup for {$setting}: expected a decimal number such as 1.5.");
        }

        if ((float) $markup < self::MIN_MARKUP || (float) $markup > self::MAX_MARKUP) {
            throw new InvalidArgumentException(sprintf(
                'Invalid markup %s for %s: it must be between %s and %s.',
                $markup,
                $setting,
                number_format(self::MIN_MARKUP, 1),
                number_format(self::MAX_MARKUP, 1),
            ));
        }

        return $markup;
    }
}
