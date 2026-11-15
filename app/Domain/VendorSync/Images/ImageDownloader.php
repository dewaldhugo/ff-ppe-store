<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Downloads one image. `http` URLs are tried over `https` first. Downloads
 * are throttled per vendor host across all workers, and a failed download
 * is retried before it is given up.
 */
final readonly class ImageDownloader
{
    /**
     * @param  array<string, mixed>  $config  The `vendorsync.images` config.
     */
    public function __construct(
        private Http $http,
        private Cache $cache,
        private array $config,
    ) {}

    /**
     * The image bytes, or null when every attempt failed.
     */
    public function download(string $url): ?string
    {
        foreach ($this->candidates($url) as $candidate) {
            $body = $this->attempt($candidate);

            if ($body !== null) {
                return $body;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidates(string $url): array
    {
        // Spaces and similar in vendor file names.
        $url = str_replace(' ', '%20', $url);

        if (stripos($url, 'http://') === 0) {
            return ['https://'.substr($url, 7), $url];
        }

        return [$url];
    }

    private function attempt(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        try {
            $response = $this->throttled($host, fn () => $this->http
                ->timeout((int) ($this->config['timeout'] ?? 60))
                ->retry((int) ($this->config['retries'] ?? 3), 500, throw: false)
                ->get($url));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        // Only real images: an error page served with status 200 is a failure.
        return $body !== '' && @getimagesizefromstring($body) !== false ? $body : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    private function throttled(string $host, callable $request): mixed
    {
        $interval = (int) ($this->config['throttle_ms'] ?? 250);
        $key = 'vendorsync.images.'.sha1($host);

        return $this->cache->lock($key.'.lock', 120)->block(120, function () use ($key, $interval, $request): mixed {
            $last = (float) $this->cache->get($key.'.last', 0);
            $wait = $interval - (int) round((microtime(true) - $last) * 1000);

            if ($last > 0 && $wait > 0) {
                Sleep::for($wait)->milliseconds();
            }

            try {
                return $request();
            } finally {
                $this->cache->put($key.'.last', microtime(true), 60);
            }
        });
    }
}
