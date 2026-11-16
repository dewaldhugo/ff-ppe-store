<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use App\Domain\VendorSync\Normalisers\Money;

/**
 * The one definition of "this Safety Jogger SKU is not priced in GBP".
 *
 * The cost is always checked; the RRP only when it has a value above zero.
 * A missing or empty currency counts as non-GBP and is reported as `(blank)`.
 * A SKU with no usable cost is not importable at all, so it has no currency
 * issue (the mapper reports it as having no cost).
 */
final class PriceCurrency
{
    /** Reported for a price that has a value but no currency. */
    public const string BLANK = '(blank)';

    /**
     * The currency that stops this SKU from being priced, or null when its
     * prices are in GBP (or it has no cost).
     *
     * @param  array<string, mixed>  $sku
     */
    public static function issue(array $sku): ?string
    {
        $cost = Money::toPence($sku['customerPrice']['value'] ?? null);

        if ($cost === null || $cost <= 0) {
            return null;
        }

        $currencies = [$sku['customerPrice']['currency'] ?? null];
        $rrp = Money::toPence($sku['rrpPrice']['value'] ?? null);

        if ($rrp !== null && $rrp > 0) {
            $currencies[] = $sku['rrpPrice']['currency'] ?? null;
        }

        foreach ($currencies as $currency) {
            $currency = strtoupper(trim((string) $currency));

            if ($currency !== 'GBP') {
                return $currency === '' ? self::BLANK : $currency;
            }
        }

        return null;
    }
}
