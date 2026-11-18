<?php

declare(strict_types=1);
use App\Support\Queues;

/**
 * @return array<string, string> Variables set (even to empty) in an env file, commented lines included.
 */
function envFileVariables(string $file, bool $includeCommented = false): array
{
    $variables = [];

    foreach (file(base_path($file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $pattern = $includeCommented ? '/^#?\s*([A-Z][A-Z0-9_]*)=(.*)$/' : '/^([A-Z][A-Z0-9_]*)=(.*)$/';

        if (preg_match($pattern, $line, $match)) {
            $variables[$match[1]] = trim($match[2], '"');
        }
    }

    return $variables;
}

const REQUIRED_ENV_VARIABLES = [
    'REDIS_PREFIX', 'REDIS_DB', 'REDIS_CACHE_DB', 'REDIS_QUEUE', 'HORIZON_PATH', 'HORIZON_DOMAIN',
    'HORIZON_PREFIX', 'HORIZON_IMAGES_PROCESSES', 'PRODUCTS_DISK_PATH', 'MEDIA_DISK', 'IMAGE_DRIVER', 'QUEUE_CONVERSIONS_BY_DEFAULT',
    'VENDORSYNC_FS_ARCHIVE_PATH', 'VENDORSYNC_IMAGE_MAX_MEGAPIXELS', 'SESSION_SECURE_COOKIE',
    'SESSION_SAME_SITE', 'LOG_DAILY_DAYS', 'SCOUT_PREFIX', 'MEILISEARCH_HOST', 'MEILISEARCH_KEY',
    'STORE_NAME', 'STORE_LEGAL_NAME', 'STORE_COMPANY_NUMBER', 'STORE_REGISTERED_OFFICE',
    'STORE_VAT_NUMBER', 'STORE_NOINDEX', 'STORE_REQUIRE_EMAIL_VERIFICATION',
];

it('lists every required variable in .env.example, active or commented', function (): void {
    expect(array_keys(envFileVariables('.env.example', includeCommented: true)))
        ->toContain(...REQUIRED_ENV_VARIABLES);
});

it('keeps .env.example local-friendly', function (): void {
    $local = envFileVariables('.env.example');

    expect($local)->toMatchArray([
        'APP_ENV' => 'local',
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'database',
        'SESSION_DRIVER' => 'database',
        'QUEUE_CONVERSIONS_BY_DEFAULT' => 'false',
        'SESSION_SECURE_COOKIE' => 'false',
    ]);
});

it('sets the production values in .env.production.example', function (): void {
    $production = envFileVariables('.env.production.example');

    expect($production)->toMatchArray([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'LOG_STACK' => 'daily',
        'LOG_LEVEL' => 'warning',
        'QUEUE_CONNECTION' => 'redis',
        'CACHE_STORE' => 'redis',
        'SESSION_DRIVER' => 'redis',
        'SESSION_SECURE_COOKIE' => 'true',
        'REDIS_DB' => '10',
        'REDIS_CACHE_DB' => '11',
        'STORE_NOINDEX' => 'true',
        // On by default. With MAIL_MAILER=log below, app:preflight fails until the operator
        // configures a mailer or sets this to false (docs/accounts.md).
        'STORE_REQUIRE_EMAIL_VERIFICATION' => 'true',
        'VENDORSYNC_SCHEDULE_ENABLED' => 'false',
        'MAIL_MAILER' => 'log',
        'QUEUE_CONVERSIONS_BY_DEFAULT' => 'false',
        'SCOUT_QUEUE' => 'false',
        'HORIZON_IMAGES_PROCESSES' => '2',
    ]);

    expect($production['REDIS_PREFIX'])->not->toBe('')
        ->and(array_keys($production))->toContain(...REQUIRED_ENV_VARIABLES)
        ->and((int) $production['REDIS_QUEUE_RETRY_AFTER'])->toBeGreaterThan(960);
});

it('does not offer MEDIA_QUEUE: the conversions queue name is fixed in config', function (): void {
    foreach (['.env.example', '.env.production.example'] as $file) {
        expect(array_keys(envFileVariables($file, includeCommented: true)))->not->toContain('MEDIA_QUEUE');
    }

    expect(config('media-library.queue_name'))->toBe(Queues::CONVERSIONS);
});

it('ships credentials as empty placeholders', function (): void {
    $production = envFileVariables('.env.production.example');

    foreach (['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'MEILISEARCH_KEY', 'VENDORSYNC_SJ_USERNAME', 'VENDORSYNC_SJ_PASSWORD', 'VENDORSYNC_SJ_TOKEN'] as $key) {
        expect($production[$key])->toBe('', $key);
    }
});
