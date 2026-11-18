<?php

declare(strict_types=1);

namespace App\Support\Preflight;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Environment checks run before a deploy (php artisan app:preflight). Each
 * check returns a Result and never throws. Redis is pinged directly here, as
 * a diagnostic: application code must not call Redis (see CLAUDE.md).
 */
final class Preflight
{
    /** Extensions that must be loaded. The image library (gd with WebP, or imagick) is checked separately. */
    public const array EXTENSIONS = ['exif', 'intl', 'bcmath', 'mbstring', 'zip', 'fileinfo', 'pcntl', 'posix', 'redis'];

    /** @var Closure(string): bool */
    private Closure $extensionLoaded;

    /**
     * @param  (Closure(string): bool)|null  $extensionLoaded  Replaced in tests.
     * @param  (Closure(): bool)|null  $webpSupport  Replaced in tests.
     */
    public function __construct(?Closure $extensionLoaded = null, private readonly ?Closure $webpSupport = null)
    {
        $this->extensionLoaded = $extensionLoaded ?? extension_loaded(...);
    }

    /**
     * @return list<Result>
     */
    public function run(): array
    {
        return [
            $this->imageLibrary(),
            ...$this->extensions(),
            $this->database(),
            $this->redis(),
            $this->meilisearch(),
            $this->productsDisk(),
            $this->queueConnection(),
            $this->mail(),
            $this->memoryLimit(),
            $this->freeDiskSpace(),
        ];
    }

    public function imageLibrary(): Result
    {
        $check = 'image library';
        $loaded = $this->extensionLoaded;

        if ($loaded('imagick')) {
            return Result::pass($check, 'imagick');
        }

        if (! $loaded('gd')) {
            return Result::fail($check, 'neither imagick nor gd is loaded');
        }

        $webp = $this->webpSupport !== null
            ? ($this->webpSupport)()
            : (function_exists('gd_info') && (gd_info()['WebP Support'] ?? false));

        return $webp
            ? Result::pass($check, 'gd with WebP')
            : Result::fail($check, 'gd is loaded without WebP support, and imagick is not loaded');
    }

    /**
     * @return list<Result>
     */
    public function extensions(): array
    {
        $loaded = $this->extensionLoaded;
        $results = [];

        foreach (self::EXTENSIONS as $extension) {
            $results[] = $loaded($extension)
                ? Result::pass("ext-{$extension}")
                : Result::fail("ext-{$extension}", "the {$extension} PHP extension is not loaded in this PHP CLI");
        }

        return $results;
    }

    public function database(): Result
    {
        try {
            DB::connection()->select('select 1');

            return Result::pass('database', 'connected to '.DB::connection()->getDatabaseName());
        } catch (Throwable $exception) {
            return Result::fail('database', 'cannot connect: '.$exception->getMessage());
        }
    }

    public function redis(): Result
    {
        try {
            Redis::connection('default')->ping();

            return Result::pass('redis', 'ping ok');
        } catch (Throwable $exception) {
            return Result::fail('redis', 'cannot reach Redis: '.$exception->getMessage());
        }
    }

    public function meilisearch(): Result
    {
        $host = rtrim((string) config('scout.meilisearch.host'), '/');
        $key = (string) config('scout.meilisearch.key');

        if ($host === '') {
            return Result::fail('meilisearch', 'MEILISEARCH_HOST is not set');
        }

        try {
            $health = Http::timeout(5)->get("{$host}/health");

            if (! $health->successful()) {
                return Result::fail('meilisearch', "{$host}/health returned HTTP {$health->status()}");
            }

            // /health needs no key, so prove the key on an authenticated route.
            $indexes = Http::timeout(5)->withToken($key)->get("{$host}/indexes", ['limit' => 1]);

            if (! $indexes->successful()) {
                return Result::fail('meilisearch', "reachable, but the key was refused (HTTP {$indexes->status()}): check MEILISEARCH_KEY");
            }

            return Result::pass('meilisearch', "{$host} reachable, key accepted");
        } catch (Throwable $exception) {
            return Result::fail('meilisearch', "cannot reach {$host}: ".$exception->getMessage());
        }
    }

    public function productsDisk(): Result
    {
        $probe = '.preflight-'.bin2hex(random_bytes(4));

        try {
            $disk = Storage::disk('products');
            $disk->put($probe, 'ok');
            $disk->delete($probe);

            return Result::pass('products disk', 'writable');
        } catch (Throwable $exception) {
            return Result::fail('products disk', 'not writable: '.$exception->getMessage());
        }
    }

    public function queueConnection(): Result
    {
        $connection = (string) config('queue.default');

        return in_array($connection, ['sync', 'null'], true)
            ? Result::fail('queue', "QUEUE_CONNECTION is {$connection}: jobs would run inside web requests or be dropped")
            : Result::pass('queue', $connection);
    }

    /**
     * Customer accounts send email: the verification link, and the password reset link. With email
     * verification on, a mailer that only writes to the log or an array means no customer can ever
     * verify, so that is a failure. With verification off it only means reset emails go nowhere.
     */
    public function mail(): Result
    {
        $check = 'mail';
        $mailer = (string) config('mail.default');
        $logOnly = in_array($mailer, ['log', 'array'], true);
        $verification = (bool) config('store.require_email_verification');

        if ($logOnly && $verification) {
            return Result::fail($check, "STORE_REQUIRE_EMAIL_VERIFICATION is on but MAIL_MAILER is {$mailer}, so customers cannot receive their verification link. Configure a real mailer, or set STORE_REQUIRE_EMAIL_VERIFICATION=false.");
        }

        if ($logOnly) {
            return Result::warn($check, "MAIL_MAILER is {$mailer}: password reset emails will not be delivered until a real mailer is configured.");
        }

        $from = (string) config('mail.from.address');

        if ($from === '' || str_ends_with($from, '@example.com')) {
            return Result::warn($check, "MAIL_FROM_ADDRESS is '{$from}': set the address customers should see emails come from.");
        }

        return Result::pass($check, "{$mailer}, email verification ".($verification ? 'on' : 'off'));
    }

    public function memoryLimit(): Result
    {
        $limit = (string) ini_get('memory_limit');

        return Result::pass('php memory_limit', $limit === '-1' ? 'unlimited' : $limit);
    }

    public function freeDiskSpace(): Result
    {
        $check = 'free disk space';
        $free = @disk_free_space(storage_path());

        if ($free === false) {
            return Result::warn($check, 'could not read the free space of '.storage_path());
        }

        $freeGb = round($free / 1024 ** 3, 1);
        $minimum = (float) config('preflight.min_free_disk_gb', 10);

        return $freeGb < $minimum
            ? Result::warn($check, "{$freeGb} GB free on the storage disk, below the {$minimum} GB threshold (PREFLIGHT_MIN_FREE_DISK_GB)")
            : Result::pass($check, "{$freeGb} GB free");
    }
}
