<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use App\Domain\VendorSync\Data\FetchOutcome;
use App\Domain\VendorSync\Exceptions\SafetyJoggerApiException;
use App\Domain\VendorSync\Support\RequestTimer;
use Illuminate\Support\Sleep;

/**
 * Fetches one product detail, and asks again when the answer cannot be used.
 *
 * The vendor's API is not stable: the same account has returned EUR prices for
 * different products on different runs, and one product answered "an
 * unexpected response" once. Two causes are retried, from one shared budget of
 * at most two extra requests per product:
 *
 * - the response is not what the endpoint promises (unexpected response);
 * - a cost or RRP of an in-scope product is not in GBP.
 *
 * The first fully GBP response is used. If none comes, the last response is
 * used and the product is left to the writer, which never lets a non-GBP
 * response replace a stored GBP price. The first re-fetch waits
 * `refetch_delay_seconds` (2), the second `refetch_second_delay_seconds` (10).
 * Every request goes through the ApiClient, so it is throttled like any other.
 */
final class ProductFetcher
{
    /** The most extra requests a product may cost, whatever the config says. */
    private const int MAX_REFETCHES = 2;

    /** SKUs listed per attempt in the diagnostics. */
    private const int DIAGNOSTIC_SKUS = 25;

    private readonly SafetyJoggerScope $scope;

    /**
     * @param  array<string, mixed>  $config  The `vendorsync.safety_jogger` config.
     */
    public function __construct(
        private readonly ApiClient $client,
        private readonly array $config,
        private readonly ?RequestTimer $timer = null,
    ) {
        $this->scope = new SafetyJoggerScope($config);
    }

    /**
     * @return array{detail: ?array<string, mixed>, outcome: FetchOutcome, error: ?string}
     */
    public function fetch(int $id): array
    {
        $max = 1 + max(0, min(self::MAX_REFETCHES, (int) ($this->config['refetches'] ?? self::MAX_REFETCHES)));

        $attempts = [];
        $currencyRefetches = 0;
        $responseRetries = 0;
        $sawNonGbp = false;
        $sawUnexpected = false;
        $last = null;
        $issues = [];
        $failure = null;

        for ($attempt = 1; $attempt <= $max; $attempt++) {
            try {
                $response = $this->client->fetchProduct($id);
            } catch (SafetyJoggerApiException $exception) {
                if ($last !== null || ! $exception->isMalformed()) {
                    // Anything but an unexpected response is the client's own
                    // business (it already retries 429, 5xx and dropped
                    // connections). A usable earlier response is kept.
                    $failure = $exception;

                    break;
                }

                $sawUnexpected = true;
                $failure = $exception;
                $attempts[] = ['attempt' => $attempt, 'result' => 'unexpected_response'];

                if ($attempt < $max) {
                    $this->wait($attempt);
                    $responseRetries++;
                }

                continue;
            }

            $failure = null;
            $last = $response;
            $issues = $this->scope->inScope($response->detail) ? PriceCurrency::skus($response->detail) : [];
            $attempts[] = $this->attempt($attempt, $response, $issues);

            if ($issues === []) {
                break;
            }

            $sawNonGbp = true;

            if ($attempt < $max) {
                $this->wait($attempt);
                $currencyRefetches++;
            }
        }

        if ($last === null) {
            $made = count($attempts) === 0 ? 1 : count($attempts);

            return [
                'detail' => null,
                'outcome' => new FetchOutcome(attempts: $made, responseRetries: $responseRetries),
                'error' => ($failure?->getMessage() ?? 'No response.').($made > 1 ? " (after {$made} attempts)" : ''),
            ];
        }

        return [
            'detail' => $last->detail,
            'outcome' => new FetchOutcome(
                attempts: count($attempts),
                currencyRefetches: $currencyRefetches,
                currencyRecovered: $sawNonGbp && $issues === [],
                responseRetries: $responseRetries,
                responseRecovered: $sawUnexpected,
                diagnostics: $issues === [] ? null : $attempts,
            ),
            'error' => null,
        ];
    }

    /**
     * @param  list<array{sku: string, issue: string, cost_currency: ?string, rrp_currency: ?string}>  $issues
     * @return array<string, mixed>
     */
    private function attempt(int $number, ProductResponse $response, array $issues): array
    {
        $topLevel = [];

        foreach ($response->detail as $key => $value) {
            if (str_contains(strtolower((string) $key), 'currenc') && is_scalar($value)) {
                $topLevel[(string) $key] = $value;
            }
        }

        return [
            'attempt' => $number,
            'result' => $issues === [] ? 'gbp' : 'non_gbp',
            'status' => $response->status,
            'fetched_at' => now()->toIso8601String(),
            'currencies_seen' => PriceCurrency::tally($response->detail),
            'non_gbp_sku_count' => count($issues),
            'non_gbp_skus' => array_slice($issues, 0, self::DIAGNOSTIC_SKUS),
            'currency_fields' => $topLevel,
            'response_headers' => $response->headers,
        ];
    }

    /**
     * The wait before re-fetch number `$retry` (1 or 2).
     */
    private function wait(int $retry): void
    {
        $seconds = max(0, (int) ($retry === 1
            ? ($this->config['refetch_delay_seconds'] ?? 2)
            : ($this->config['refetch_second_delay_seconds'] ?? 10)));

        if ($seconds === 0) {
            return;
        }

        $this->timer?->waited($seconds);
        Sleep::for($seconds)->seconds();
    }
}
