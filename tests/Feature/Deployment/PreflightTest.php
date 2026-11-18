<?php

declare(strict_types=1);

use App\Support\Preflight\Preflight;
use App\Support\Preflight\Result;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

function allLoaded(): Closure
{
    return fn (string $extension): bool => true;
}

function onlyLoaded(string ...$loaded): Closure
{
    return fn (string $extension): bool => in_array($extension, $loaded, true);
}

function fakeMeilisearch(int $health = 200, int $indexes = 200): void
{
    config(['scout.meilisearch.host' => 'http://meili.test:7700', 'scout.meilisearch.key' => 'secret']);

    // A new factory each time: stubs from an earlier call would match first.
    Http::swap(new Factory);
    Http::fake([
        'meili.test:7700/health' => Http::response(['status' => 'available'], $health),
        'meili.test:7700/indexes*' => Http::response(['results' => []], $indexes),
    ]);
}

it('passes when every extension is loaded and names each missing one', function (): void {
    expect(array_map(fn (Result $r): string => $r->status, (new Preflight(allLoaded()))->extensions()))
        ->each->toBe(Result::PASS);

    $results = (new Preflight(onlyLoaded('exif', 'intl')))->extensions();
    $failed = array_map(fn (Result $r): string => $r->check, array_filter($results, fn (Result $r): bool => $r->failed()));

    expect(array_values($failed))->toBe(['ext-bcmath', 'ext-mbstring', 'ext-zip', 'ext-fileinfo', 'ext-pcntl', 'ext-posix', 'ext-redis'])
        ->and($results[0]->check)->toBe('ext-exif');
});

it('accepts imagick, or gd with WebP, and refuses anything else', function (): void {
    expect((new Preflight(onlyLoaded('imagick')))->imageLibrary()->status)->toBe(Result::PASS)
        ->and((new Preflight(onlyLoaded('gd'), fn (): bool => true))->imageLibrary()->status)->toBe(Result::PASS)
        ->and((new Preflight(onlyLoaded('gd'), fn (): bool => false))->imageLibrary()->failed())->toBeTrue()
        ->and((new Preflight(onlyLoaded()))->imageLibrary()->failed())->toBeTrue();
});

it('checks the database connection', function (): void {
    expect((new Preflight)->database()->status)->toBe(Result::PASS);

    DB::shouldReceive('connection')->andThrow(new RuntimeException('connection refused'));

    $result = (new Preflight)->database();

    expect($result->failed())->toBeTrue()
        ->and($result->message)->toContain('connection refused');
});

it('checks the Redis connection', function (): void {
    Redis::shouldReceive('connection')->with('default')->once()->andReturn(new class
    {
        public function ping(): bool
        {
            return true;
        }
    });

    expect((new Preflight)->redis()->status)->toBe(Result::PASS);

    Redis::shouldReceive('connection')->with('default')->andThrow(new RuntimeException('no route to host'));

    expect((new Preflight)->redis()->failed())->toBeTrue();
});

it('checks that Meilisearch is reachable and accepts the key', function (): void {
    fakeMeilisearch();
    expect((new Preflight)->meilisearch()->status)->toBe(Result::PASS);

    fakeMeilisearch(health: 503);
    expect((new Preflight)->meilisearch()->message)->toContain('HTTP 503');

    fakeMeilisearch(indexes: 403);
    $refused = (new Preflight)->meilisearch();
    expect($refused->failed())->toBeTrue()
        ->and($refused->message)->toContain('key was refused');

    config(['scout.meilisearch.host' => '']);
    expect((new Preflight)->meilisearch()->message)->toContain('MEILISEARCH_HOST');
});

it('fails when Meilisearch cannot be reached at all', function (): void {
    config(['scout.meilisearch.host' => 'http://meili.test:7700']);
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect((new Preflight)->meilisearch()->message)->toContain('cannot reach');
});

it('checks that the products disk is writable and leaves no probe file behind', function (): void {
    Storage::fake('products');

    expect((new Preflight)->productsDisk()->status)->toBe(Result::PASS);
    Storage::disk('products')->assertDirectoryEmpty('');

    Storage::shouldReceive('disk')->with('products')->andThrow(new RuntimeException('read-only file system'));

    expect((new Preflight)->productsDisk()->failed())->toBeTrue();
});

it('refuses the sync and null queue connections', function (): void {
    foreach (['sync', 'null'] as $connection) {
        config(['queue.default' => $connection]);

        expect((new Preflight)->queueConnection()->failed())->toBeTrue();
    }

    config(['queue.default' => 'redis']);

    expect((new Preflight)->queueConnection()->status)->toBe(Result::PASS);
});

it('reports the CLI memory limit', function (): void {
    $result = (new Preflight)->memoryLimit();

    expect($result->status)->toBe(Result::PASS)
        ->and($result->message)->not->toBe('');
});

it('warns, without failing, when free disk space is below the configured threshold', function (): void {
    config(['preflight.min_free_disk_gb' => 100_000_000]);
    $low = (new Preflight)->freeDiskSpace();

    expect($low->status)->toBe(Result::WARN)
        ->and($low->failed())->toBeFalse()
        ->and($low->message)->toContain('100000000');

    config(['preflight.min_free_disk_gb' => 0]);

    expect((new Preflight)->freeDiskSpace()->status)->toBe(Result::PASS);
});

it('defaults the free disk threshold to 10 GB', function (): void {
    expect(config('preflight.min_free_disk_gb'))->toBe(10.0);
});

it('fails when email verification is on and the mailer is log or array', function (string $mailer): void {
    config(['mail.default' => $mailer, 'store.require_email_verification' => true]);

    $result = (new Preflight)->mail();

    expect($result->status)->toBe(Result::FAIL)
        ->and($result->failed())->toBeTrue()
        ->and($result->message)->toContain('MAIL_MAILER is '.$mailer)
        ->toContain('STORE_REQUIRE_EMAIL_VERIFICATION=false');
})->with(['log', 'array']);

it('warns, without failing, when verification is off and the mailer is log or array', function (string $mailer): void {
    config(['mail.default' => $mailer, 'store.require_email_verification' => false]);

    $result = (new Preflight)->mail();

    expect($result->status)->toBe(Result::WARN)
        ->and($result->failed())->toBeFalse()
        ->and($result->message)->toContain('password reset emails will not be delivered')
        ->and(strtolower($result->message))->not->toContain('fail');
})->with(['log', 'array']);

it('passes with a real mailer and a real from address, whatever the verification switch', function (bool $verification): void {
    config(['mail.default' => 'smtp', 'mail.from.address' => 'orders@store.test', 'store.require_email_verification' => $verification]);

    $result = (new Preflight)->mail();

    expect($result->status)->toBe(Result::PASS)
        ->and($result->message)->toContain('smtp');
})->with([true, false]);

it('warns when a real mailer has no usable from address', function (string $from): void {
    config(['mail.default' => 'smtp', 'mail.from.address' => $from, 'store.require_email_verification' => true]);

    $result = (new Preflight)->mail();

    expect($result->status)->toBe(Result::WARN)
        ->and($result->message)->toContain('MAIL_FROM_ADDRESS')
        ->and(strtolower($result->message))->not->toContain('fail');
})->with(['', 'hello@example.com']);

it('exits non-zero when verification is on and the mailer is log, naming the mail check', function (): void {
    $this->app->instance(Preflight::class, new Preflight(allLoaded()));
    fakeMeilisearch();
    Storage::fake('products');
    config(['queue.default' => 'redis', 'preflight.min_free_disk_gb' => 0, 'mail.default' => 'log', 'store.require_email_verification' => true]);
    Redis::shouldReceive('connection')->with('default')->andReturn(new class
    {
        public function ping(): bool
        {
            return true;
        }
    });

    $this->artisan('app:preflight')
        ->expectsOutputToContain('mail: STORE_REQUIRE_EMAIL_VERIFICATION is on but MAIL_MAILER is log')
        ->assertFailed();
});

it('keeps the exit code zero and prints no failure when only the mail warning applies', function (): void {
    $this->app->instance(Preflight::class, new Preflight(allLoaded()));
    fakeMeilisearch();
    Storage::fake('products');
    config(['queue.default' => 'redis', 'preflight.min_free_disk_gb' => 0, 'mail.default' => 'log', 'store.require_email_verification' => false]);
    Redis::shouldReceive('connection')->with('default')->andReturn(new class
    {
        public function ping(): bool
        {
            return true;
        }
    });

    $this->artisan('app:preflight')
        ->expectsOutputToContain('Warning: mail: MAIL_MAILER is log')
        ->expectsOutputToContain('Preflight passed.')
        ->doesntExpectOutputToContain('preflight check(s) failed')
        ->assertSuccessful();
});

it('exits non-zero and names every failed check', function (): void {
    $this->app->instance(Preflight::class, new Preflight(onlyLoaded('imagick', 'exif')));
    fakeMeilisearch();
    Storage::fake('products');
    config(['queue.default' => 'sync']);

    $this->artisan('app:preflight')
        ->expectsOutputToContain('ext-pcntl: the pcntl PHP extension is not loaded')
        ->expectsOutputToContain('queue: QUEUE_CONNECTION is sync')
        ->assertFailed();
});

it('exits zero when every check passes', function (): void {
    $this->app->instance(Preflight::class, new Preflight(allLoaded()));
    fakeMeilisearch();
    Storage::fake('products');
    config(['queue.default' => 'redis', 'preflight.min_free_disk_gb' => 0]);
    config(['mail.default' => 'smtp', 'mail.from.address' => 'orders@store.test', 'store.require_email_verification' => true]);
    Redis::shouldReceive('connection')->with('default')->andReturn(new class
    {
        public function ping(): bool
        {
            return true;
        }
    });

    $this->artisan('app:preflight')
        ->expectsOutputToContain('Preflight passed.')
        ->assertSuccessful();
});
