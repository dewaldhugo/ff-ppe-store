<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Images;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Downloads one image. `http` URLs are tried over `https` first.
 *
 * The per-host throttle is a rate limit: at least `throttle_ms` between the
 * starts of two requests to one host, across all workers. Each HTTP attempt
 * (every retry, and every https/http candidate) reserves its own slot under a
 * short lock, sleeps until the slot, and then makes the request holding no
 * lock. A hanging request therefore stalls only its own worker.
 *
 * Only connection errors, HTTP 5xx and 429 are retried, up to `retries`
 * attempts in total. A 404 or 403 fails at once.
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

    public function download(string $url): DownloadResult
    {
        $result = null;

        foreach ($this->candidates($url) as $candidate) {
            $result = $this->attempts($candidate);

            // A busy slot lock is not the URL's fault: do not wait again for
            // the next candidate.
            if ($result->succeeded() || $result->lockTimedOut) {
                return $result;
            }
        }

        return $result ?? DownloadResult::failed('download failed: no URL to try');
    }

    /**
     * The cache lock that hands out request slots for a host. Held for
     * milliseconds, never during a request.
     */
    public function lockKey(string $host): string
    {
        return 'vendorsync.images.'.sha1($host).'.lock';
    }

    /**
     * Reserve the next free request slot for a host and return it as
     * milliseconds since the epoch. The caller sleeps until then. Two
     * reservations are at least `throttle_ms` apart.
     *
     * @throws LockTimeoutException When the slot lock stays busy for `slot_lock_seconds`.
     */
    public function reserveSlot(string $host): int
    {
        $gap = $this->throttleMs();

        if ($gap <= 0) {
            return $this->nowMs();
        }

        $seconds = $this->slotLockSeconds();
        $key = 'vendorsync.images.'.sha1($host).'.next';

        return $this->cache->lock($this->lockKey($host), $seconds)->block($seconds, function () use ($key, $gap): int {
            $slot = max($this->nowMs(), (int) $this->cache->get($key, 0));

            $this->cache->put($key, $slot + $gap, max(60, (int) ceil($gap * 10 / 1000)));

            return $slot;
        });
    }

    /**
     * The longest one `download()` call can take: every candidate URL, every
     * attempt, each with the full request time, the wait for the slot lock,
     * the slot gap and the pause between attempts. The job's time budget
     * uses it to decide whether another download can still finish.
     */
    public function worstCaseDownloadSeconds(string $url): int
    {
        $attemptMs = $this->slotLockSeconds() * 1000 + $this->throttleMs() + $this->timeoutSeconds() * 1000;
        $candidateMs = $this->maxAttempts() * $attemptMs + ($this->maxAttempts() - 1) * $this->backoffMs();

        return (int) ceil(count($this->candidates($url)) * $candidateMs / 1000);
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

    private function attempts(string $url): DownloadResult
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $max = $this->maxAttempts();
        $failure = 'download failed';
        $made = 0;

        while ($made < $max) {
            try {
                $slot = $this->reserveSlot($host);
            } catch (LockTimeoutException) {
                return DownloadResult::failed(
                    "download failed: could not reserve a request slot on {$host} within {$this->slotLockSeconds()}s (host throttle lock busy)",
                    lockTimedOut: true,
                );
            }

            $wait = $slot - $this->nowMs();

            if ($wait > 0) {
                Sleep::for($wait)->milliseconds();
            }

            $made++;
            $retry = false;

            try {
                $response = $this->http
                    ->timeout($this->timeoutSeconds())
                    ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10))
                    ->get($url);
            } catch (ConnectionException $exception) {
                $failure = 'connection failed or timed out: '.$exception->getMessage();
                $retry = true;
                $response = null;
            } catch (Throwable $exception) {
                return DownloadResult::failed('download failed: '.class_basename($exception).': '.$exception->getMessage());
            }

            if ($response !== null) {
                if ($response->successful()) {
                    $body = $response->body();

                    // Only real images: an error page served with status 200 is a failure.
                    return $body !== '' && @getimagesizefromstring($body) !== false
                        ? DownloadResult::ok($body)
                        : DownloadResult::failed('download failed: the response is not an image');
                }

                $status = $response->status();
                $failure = "HTTP {$status}";
                $retry = $status >= 500 || $status === 429;
            }

            if (! $retry) {
                break;
            }

            if ($made < $max && $this->backoffMs() > 0) {
                Sleep::for($this->backoffMs())->milliseconds();
            }
        }

        return DownloadResult::failed('download failed: '.$failure.($made > 1 ? " (after {$made} attempts)" : ''));
    }

    private function nowMs(): int
    {
        return (int) Carbon::now()->format('Uv');
    }

    private function maxAttempts(): int
    {
        return max(1, (int) ($this->config['retries'] ?? 3));
    }

    private function timeoutSeconds(): int
    {
        return (int) ($this->config['timeout'] ?? 60);
    }

    private function backoffMs(): int
    {
        return (int) ($this->config['retry_backoff_ms'] ?? 500);
    }

    private function throttleMs(): int
    {
        return (int) ($this->config['throttle_ms'] ?? 250);
    }

    private function slotLockSeconds(): int
    {
        return max(1, (int) ($this->config['slot_lock_seconds'] ?? 5));
    }
}
