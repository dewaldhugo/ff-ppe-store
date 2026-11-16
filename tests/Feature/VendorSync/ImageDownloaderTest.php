<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\ImageDownloader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\VendorSync;

/*
 * The per-host throttle is a rate limit: at least 250 ms between request
 * starts, across workers, one slot per HTTP attempt, and no lock held while a
 * request is in flight. The clock is frozen and sleeping advances it, so a
 * hanging host costs no real time.
 */

const IMAGE_HOST = 'img.example.test';

beforeEach(function (): void {
    VendorSync::boot();
    Carbon::setTestNow('2026-11-16 12:00:00');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function (): void {
    Carbon::setTestNow();
    Sleep::fake(false);
});

/**
 * @param  array<string, mixed>  $config
 */
function downloader(array $config = []): ImageDownloader
{
    return new ImageDownloader(app(Factory::class), app('cache.store'), array_merge(config('vendorsync.images'), $config));
}

function clockMs(): int
{
    return (int) Carbon::now()->format('Uv');
}

function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

it('hands out request slots at least 250 ms apart, per host', function (): void {
    $downloader = downloader();
    $start = clockMs();

    $slots = [$downloader->reserveSlot(IMAGE_HOST), $downloader->reserveSlot(IMAGE_HOST), $downloader->reserveSlot(IMAGE_HOST)];

    expect($slots)->toBe([$start, $start + 250, $start + 500])
        // Another host has its own queue.
        ->and($downloader->reserveSlot('other.example.test'))->toBe($start);

    // Once the queue has drained, the next slot is now, not a backlog.
    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    expect($downloader->reserveSlot(IMAGE_HOST))->toBe(clockMs());
});

it('downloads an image in one request', function (): void {
    Http::fake(fn () => Http::response(tinyPng(), 200));

    $result = downloader()->download('http://'.IMAGE_HOST.'/a.png');

    // The https candidate is tried first and, when it works, is the only one.
    expect($result->succeeded())->toBeTrue()
        ->and($result->body)->toBe(tinyPng());
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://'.IMAGE_HOST.'/a.png');
});

it('does not hold the slot lock during a hanging request, so another worker is not blocked', function (): void {
    $other = downloader();
    $observed = [];

    // Every attempt hangs for the full 60 s timeout. While it hangs, a second
    // worker reserves a slot on the same host and the lock is free.
    Http::fake(function (Request $request) use ($other, &$observed) {
        $probe = Cache::lock($other->lockKey(IMAGE_HOST), 5);
        $free = $probe->get();
        $probe->release();

        $observed[] = ['lock_free' => $free, 'slot' => $other->reserveSlot(IMAGE_HOST)];

        Carbon::setTestNow(Carbon::now()->addSeconds(60));

        throw new ConnectionException('cURL error 28: Operation timed out after 60001 milliseconds');
    });

    $started = clockMs();
    $result = downloader()->download('https://'.IMAGE_HOST.'/a.png');

    // 3 attempts x 60 s + 2 x 500 ms backoff.
    expect(clockMs() - $started)->toBe(181_000)
        ->and($result->succeeded())->toBeFalse()
        ->and($result->lockTimedOut)->toBeFalse()
        ->and($result->failure)->toContain('connection failed or timed out')
        ->and($result->failure)->toContain('after 3 attempts')
        ->and($observed)->toHaveCount(3)
        ->and(array_column($observed, 'lock_free'))->toBe([true, true, true]);
});

it('takes a slot for every HTTP attempt, retries and candidates included', function (): void {
    $times = [];
    $urls = [];

    Http::fake(function (Request $request) use (&$times, &$urls) {
        $times[] = clockMs();
        $urls[] = $request->url();

        return Http::response('unavailable', 503);
    });

    $start = clockMs();
    $result = downloader(['retry_backoff_ms' => 0])->download('http://'.IMAGE_HOST.'/a.png');

    // Both candidates, three attempts each: six requests, 250 ms apart.
    expect($times)->toBe([$start, $start + 250, $start + 500, $start + 750, $start + 1000, $start + 1250])
        ->and(array_slice($urls, 0, 3))->each->toStartWith('https://')
        ->and(array_slice($urls, 3))->each->toStartWith('http://')
        ->and(Cache::get('vendorsync.images.'.sha1(IMAGE_HOST).'.next'))->toBe($start + 1500)
        ->and($result->failure)->toBe('download failed: HTTP 503 (after 3 attempts)');
});

it('retries only connection errors, 5xx and 429', function (int|string $outcome, int $requests): void {
    $sent = 0;

    // Counted here: a request that throws is not recorded by Http::fake.
    Http::fake(function () use ($outcome, &$sent) {
        $sent++;

        if ($outcome === 'connection') {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }

        return $outcome === 'html'
            ? Http::response('<html>Not found</html>', 200)
            : Http::response('no', $outcome);
    });

    $result = downloader()->download('https://'.IMAGE_HOST.'/a.png');

    expect($result->succeeded())->toBeFalse()
        ->and($sent)->toBe($requests);
})->with([
    '404' => [404, 1],
    '403' => [403, 1],
    '410' => [410, 1],
    '400' => [400, 1],
    'a 200 that is not an image' => ['html', 1],
    '429' => [429, 3],
    '500' => [500, 3],
    '502' => [502, 3],
    '503' => [503, 3],
    'a connection error' => ['connection', 3],
]);

it('fails an image whose slot lock stays busy, with its own reason, and does not wait twice', function (): void {
    $downloader = downloader();
    Http::fake();

    // Another process holds the host's slot lock.
    $held = Cache::lock($downloader->lockKey(IMAGE_HOST), 600);
    expect($held->get())->toBeTrue();

    $started = clockMs();
    $result = $downloader->download('http://'.IMAGE_HOST.'/a.png');
    $waited = clockMs() - $started;

    expect($result->succeeded())->toBeFalse()
        ->and($result->lockTimedOut)->toBeTrue()
        ->and($result->failure)->toBe('download failed: could not reserve a request slot on '.IMAGE_HOST.' within 5s (host throttle lock busy)')
        // About one wait of 5 s, not one per candidate URL.
        ->and($waited)->toBeGreaterThanOrEqual(4_500)
        ->and($waited)->toBeLessThan(6_000);

    Http::assertNothingSent();
});

it('derives the worst-case download time from the configuration', function (): void {
    // 3 x (5 s lock wait + 0.25 s slot + 60 s request) + 2 x 0.5 s backoff.
    expect(downloader()->worstCaseDownloadSeconds('https://'.IMAGE_HOST.'/a.png'))->toBe(197)
        ->and(downloader()->worstCaseDownloadSeconds('http://'.IMAGE_HOST.'/a.png'))->toBe(394)
        ->and(downloader(['retries' => 2, 'timeout' => 30, 'retry_backoff_ms' => 100, 'slot_lock_seconds' => 2])
            ->worstCaseDownloadSeconds('https://'.IMAGE_HOST.'/a.png'))->toBe(65);
});

it('takes two slots when a retry succeeds', function (): void {
    $times = [];
    $calls = 0;

    Http::fake(function () use (&$times, &$calls) {
        $times[] = clockMs();

        return ++$calls === 1 ? Http::response('unavailable', 503) : Http::response(tinyPng(), 200);
    });

    $start = clockMs();
    $result = downloader(['retry_backoff_ms' => 0])->download('https://'.IMAGE_HOST.'/a.png');

    // A 503, then the image: two requests, two slots, 250 ms apart, nothing left reserved.
    expect($result->succeeded())->toBeTrue()
        ->and($result->body)->toBe(tinyPng())
        ->and($times)->toBe([$start, $start + 250])
        ->and(Cache::get('vendorsync.images.'.sha1(IMAGE_HOST).'.next'))->toBe($start + 500);
});

it('makes one request per candidate on a 404, so an http URL makes two', function (): void {
    $urls = [];

    Http::fake(function (Request $request) use (&$urls) {
        $urls[] = $request->url();

        return Http::response('missing', 404);
    });

    $result = downloader()->download('http://'.IMAGE_HOST.'/a.png');

    expect($urls)->toBe(['https://'.IMAGE_HOST.'/a.png', 'http://'.IMAGE_HOST.'/a.png'])
        ->and($result->failure)->toBe('download failed: HTTP 404');
});
