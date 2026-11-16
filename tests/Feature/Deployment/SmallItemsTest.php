<?php

declare(strict_types=1);

/**
 * config/filesystems.php as it evaluates under the given APP_ENV.
 *
 * @return array<string, mixed>
 */
function filesystemsConfigFor(string $environment): array
{
    $original = $_ENV['APP_ENV'] ?? null;
    $originalServer = $_SERVER['APP_ENV'] ?? null;

    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $environment;
    putenv("APP_ENV={$environment}");

    try {
        return require config_path('filesystems.php');
    } finally {
        $_ENV['APP_ENV'] = $original;
        $_SERVER['APP_ENV'] = $originalServer;
        putenv("APP_ENV={$original}");
    }
}

it('does not serve the local disk over HTTP in production', function (): void {
    expect(filesystemsConfigFor('production')['disks']['local']['serve'])->toBeFalse()
        ->and(filesystemsConfigFor('local')['disks']['local']['serve'])->toBeTrue();
});

it('keeps crawlers out of the admin, Horizon, cart, checkout and account, and nothing else', function (): void {
    $lines = array_map('trim', file(public_path('robots.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

    expect($lines)->toBe([
        'User-agent: *',
        'Disallow: /lunar',
        'Disallow: /horizon',
        'Disallow: /cart',
        'Disallow: /checkout',
        'Disallow: /account',
    ]);
});
