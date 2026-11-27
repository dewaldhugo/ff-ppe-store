<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * South African rand to the pound, for the small rand figure beside the money tiles on the admin dashboard. The rate is the
 * European Central Bank's reference rate for the day, from the free Frankfurter API (no key, no account). Only the currency
 * pair goes out, nothing about the store or its orders.
 *
 * A good rate is kept for six hours, so the dashboard (which refreshes every minute) does not call out each time. When the
 * API cannot be reached or answers with anything that is not a positive number, there is no rate: the tiles show pounds only,
 * and the failure is remembered for five minutes so a slow or down API does not hold up every refresh. No older rate is used
 * in its place.
 */
final class ZarRate
{
    public const string CACHE_KEY = 'dashboard.zar_per_gbp';

    public const string URL = 'https://api.frankfurter.dev/v1/latest';

    private const int GOOD_MINUTES = 360;

    private const int FAILED_MINUTES = 5;

    private const int TIMEOUT_SECONDS = 3;

    /** Rand to the pound, or null when it is not known. */
    public function perPound(): ?float
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached === false) {
            return null;
        }

        if (is_numeric($cached) && (float) $cached > 0) {
            return (float) $cached;
        }

        $rate = $this->fetch();

        Cache::put(self::CACHE_KEY, $rate ?? false, now()->addMinutes($rate === null ? self::FAILED_MINUTES : self::GOOD_MINUTES));

        return $rate;
    }

    private function fetch(): ?float
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::URL, ['base' => 'GBP', 'symbols' => 'ZAR']);

            $rate = $response->successful() ? $response->json('rates.ZAR') : null;
        } catch (Throwable) {
            return null;
        }

        return is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null;
    }
}
