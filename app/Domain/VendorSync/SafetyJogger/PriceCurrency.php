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
     * Every SKU of a product detail that is not priced in GBP, with the
     * currency field of its cost and of its RRP exactly as the vendor sent
     * them (null when absent).
     *
     * @param  array<string, mixed>  $detail
     * @return list<array{sku: string, issue: string, cost_currency: ?string, rrp_currency: ?string}>
     */
    public static function skus(array $detail): array
    {
        $found = [];

        foreach (self::allSkus($detail) as $sku) {
            $issue = self::issue($sku);

            if ($issue === null) {
                continue;
            }

            $found[] = [
                'sku' => (string) ($sku['id'] ?? ''),
                'issue' => $issue,
                'cost_currency' => self::raw($sku['customerPrice']['currency'] ?? null),
                'rrp_currency' => self::raw($sku['rrpPrice']['currency'] ?? null),
            ];
        }

        return $found;
    }

    /**
     * How many SKU costs and RRPs carry each currency (`(blank)` for none).
     * An RRP of zero or no value is not counted, as in issue().
     *
     * @param  array<string, mixed>  $detail
     * @return array{cost: array<string, int>, rrp: array<string, int>}
     */
    public static function tally(array $detail): array
    {
        $tally = ['cost' => [], 'rrp' => []];

        foreach (self::allSkus($detail) as $sku) {
            foreach (['cost' => 'customerPrice', 'rrp' => 'rrpPrice'] as $kind => $field) {
                $pence = Money::toPence($sku[$field]['value'] ?? null);

                if ($pence === null || $pence <= 0) {
                    continue;
                }

                $currency = strtoupper(trim((string) ($sku[$field]['currency'] ?? '')));
                $currency = $currency === '' ? self::BLANK : $currency;
                $tally[$kind][$currency] = ($tally[$kind][$currency] ?? 0) + 1;
            }
        }

        return $tally;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return list<array<string, mixed>>
     */
    private static function allSkus(array $detail): array
    {
        $skus = [];

        foreach ($detail['colors'] ?? [] as $colour) {
            foreach (is_array($colour) ? ($colour['skus'] ?? []) : [] as $sku) {
                if (is_array($sku)) {
                    $skus[] = $sku;
                }
            }
        }

        return $skus;
    }

    private static function raw(mixed $currency): ?string
    {
        return $currency === null ? null : (string) $currency;
    }

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
