<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

use App\Domain\VendorSync\Exceptions\SafetyJoggerApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Read-only Safety Jogger API client.
 *
 * Read-only by construction: the public methods are the product list, the
 * product detail and the stock feed, and every request is built from the
 * Endpoint allowlist. There is no generic request method.
 *
 * Credentials come from config (environment) only and never appear in logs
 * or exception messages.
 */
final class ApiClient
{
    private const string CACHE_KEY = 'vendorsync.safety_jogger.auth';

    private float $lastRequestAt = 0.0;

    /**
     * @param  array<string, mixed>  $config  The `vendorsync.safety_jogger` config.
     */
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly array $config,
    ) {}

    /**
     * GET /product: every product available to the account.
     *
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        return $this->list($this->authenticated(Endpoint::ProductList), Endpoint::ProductList);
    }

    /**
     * GET /product/{id}: the detail of one product.
     *
     * @return array<string, mixed>
     */
    public function product(int $id): array
    {
        $data = $this->authenticated(Endpoint::ProductDetail, $id)->json();

        if (! is_array($data) || array_is_list($data)) {
            throw SafetyJoggerApiException::malformed('GET', Endpoint::ProductDetail->path($id));
        }

        return $data;
    }

    /**
     * GET /stock: the stock of every SKU, in one call.
     *
     * @return list<array<string, mixed>>
     */
    public function stock(): array
    {
        return $this->list($this->authenticated(Endpoint::Stock), Endpoint::Stock);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(Response $response, Endpoint $endpoint): array
    {
        $data = $response->json();

        if (! is_array($data) || ! array_is_list($data)) {
            throw SafetyJoggerApiException::malformed($endpoint->method(), $endpoint->path());
        }

        return $data;
    }

    /**
     * Send a data request with the cached authentication hash. On a 401 the
     * client authenticates again once and retries.
     */
    private function authenticated(Endpoint $endpoint, ?int $id = null): Response
    {
        $response = $this->send($endpoint, $id, $this->headers($this->hash()));

        if ($response->status() === 401) {
            $response = $this->send($endpoint, $id, $this->headers($this->hash(fresh: true)));
        }

        if (! $response->successful()) {
            throw SafetyJoggerApiException::status($endpoint->method(), $endpoint->path($id), $response->status());
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $hash): array
    {
        return [
            'username' => (string) $this->config['username'],
            'secret' => $hash,
            'language' => (string) ($this->config['language'] ?? 'en'),
        ];
    }

    /**
     * The authentication hash, cached until one hour before it expires.
     */
    private function hash(bool $fresh = false): string
    {
        if (! $fresh) {
            $cached = $this->cache->get(self::CACHE_KEY);

            if (is_string($cached)) {
                try {
                    return Crypt::decryptString($cached);
                } catch (Throwable) {
                    // Unreadable (for example after an APP_KEY change): log in again.
                }
            }
        }

        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;
        $token = $this->config['token'] ?? null;

        if (blank($this->config['base_url'] ?? null) || blank($username) || (blank($password) && blank($token))) {
            throw SafetyJoggerApiException::notConfigured();
        }

        // The token is used instead of the password when set.
        $credentials = filled($token) ? ['token' => $token] : ['password' => $password];

        $response = $this->send(Endpoint::Login, null, [], [
            'username' => $username,
            ...$credentials,
            'validity' => (int) ($this->config['validity_days'] ?? 30),
        ]);

        if (! $response->successful()) {
            throw SafetyJoggerApiException::status('POST', Endpoint::Login->path(), $response->status());
        }

        $hash = $response->json('hash');

        if (! is_string($hash) || $hash === '') {
            throw SafetyJoggerApiException::malformed('POST', Endpoint::Login->path());
        }

        $this->cache->put(self::CACHE_KEY, Crypt::encryptString($hash), $this->cacheUntil($response->json('expirationDate')));

        return $hash;
    }

    /**
     * One hour before the expiry the API reports. When the expiry cannot be
     * read the hash is kept for a day.
     */
    private function cacheUntil(mixed $expirationDate): CarbonImmutable
    {
        $fallback = CarbonImmutable::now()->addDay();

        try {
            $expires = match (true) {
                is_int($expirationDate), is_float($expirationDate) => CarbonImmutable::createFromTimestampMs((int) $expirationDate),
                is_string($expirationDate) && $expirationDate !== '' => CarbonImmutable::parse($expirationDate),
                default => null,
            };
        } catch (Throwable) {
            $expires = null;
        }

        if ($expires === null) {
            return $fallback;
        }

        $until = $expires->subHour();

        return $until->isFuture() ? $until : CarbonImmutable::now()->addMinute();
    }

    /**
     * The single place a request is sent. Throttled, with a timeout, and
     * retried with backoff on 429, 5xx and connection failures.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    private function send(Endpoint $endpoint, ?int $id, array $headers, array $body = []): Response
    {
        $method = $endpoint->method();
        $path = $endpoint->path($id);
        $url = rtrim((string) ($this->config['base_url'] ?? ''), '/').'/'.$path;
        $retries = (int) ($this->config['retries'] ?? 3);

        if (blank($this->config['base_url'] ?? null)) {
            throw SafetyJoggerApiException::notConfigured();
        }

        for ($attempt = 0; ; $attempt++) {
            $this->throttle();

            try {
                $request = $this->http
                    ->timeout((int) ($this->config['timeout'] ?? 30))
                    ->acceptJson()
                    ->withHeaders($headers);

                $response = $method === 'POST' ? $request->post($url, $body) : $request->get($url);
            } catch (ConnectionException) {
                if ($attempt >= $retries) {
                    throw SafetyJoggerApiException::unreachable($method, $path);
                }

                Sleep::for(2 ** $attempt)->seconds();

                continue;
            }

            $retryable = $response->status() === 429 || $response->serverError();

            if (! $retryable || $attempt >= $retries) {
                return $response;
            }

            $retryAfter = (int) $response->header('Retry-After');

            Sleep::for($retryAfter > 0 ? min($retryAfter, 60) : 2 ** $attempt)->seconds();
        }
    }

    /**
     * Keep at least 1 / requests_per_second between two requests.
     */
    private function throttle(): void
    {
        $perSecond = max(1, (int) ($this->config['requests_per_second'] ?? 2));
        $interval = 1 / $perSecond;
        $elapsed = microtime(true) - $this->lastRequestAt;

        if ($this->lastRequestAt > 0 && $elapsed < $interval) {
            Sleep::for((int) round(($interval - $elapsed) * 1_000_000))->microseconds();
        }

        $this->lastRequestAt = microtime(true);
    }
}
