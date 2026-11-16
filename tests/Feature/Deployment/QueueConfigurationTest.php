<?php

declare(strict_types=1);

use App\Domain\VendorSync\Images\ImageDownloader;
use App\Domain\VendorSync\Images\SyncProductImages;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Horizon supervisors for one environment, defaults merged in.
 *
 * @return array<string, array<string, mixed>>
 */
function horizonSupervisors(string $environment): array
{
    $defaults = config('horizon.defaults');
    $supervisors = [];

    foreach (config("horizon.environments.{$environment}") as $name => $overrides) {
        $supervisors[$name] = array_replace($defaults[$name], $overrides);
    }

    return $supervisors;
}

/**
 * Every queued job class under app/ with the timeout it declares.
 *
 * @return array<class-string, int>
 */
function appJobTimeouts(): array
{
    $timeouts = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen(app_path()) + 1, -4);
        $class = 'App'.chr(92).str_replace(DIRECTORY_SEPARATOR, chr(92), $relative);

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $property = new ReflectionClass($class);

        if ($property->hasProperty('timeout') && $property->getProperty('timeout')->getDefaultValue() !== null) {
            $timeouts[$class] = (int) $property->getProperty('timeout')->getDefaultValue();
        }
    }

    return $timeouts;
}

/**
 * Every class under app/ that can be queued.
 *
 * @return list<class-string>
 */
function appQueueableClasses(): array
{
    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen(app_path()) + 1, -4);
        $class = 'App'.chr(92).str_replace(DIRECTORY_SEPARATOR, chr(92), $relative);

        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * Whether a queueable class takes its queue from App\Support\Queues: it calls
 * onQueue() or allOnQueue() with a Queues constant, or declares a $queue
 * default that is one of the Queues values.
 */
function setsQueueFromQueues(string $class): bool
{
    $reflection = new ReflectionClass($class);

    if (preg_match('/->(?:onQueue|allOnQueue)\(\s*Queues::[A-Z_]+\s*\)/', (string) file_get_contents((string) $reflection->getFileName())) === 1) {
        return true;
    }

    return $reflection->hasProperty('queue')
        && in_array($reflection->getProperty('queue')->getDefaultValue(), array_values((new ReflectionClass(Queues::class))->getConstants()), true);
}

it('sets the queue of every queueable class from App\Support\Queues', function (): void {
    // A class that deliberately runs on the connection default queue goes
    // here, with the reason. Empty on purpose: a new job needs a decision.
    $allowed = [];

    $classes = appQueueableClasses();

    expect($classes)->toContain(SyncProductImages::class);

    $unrouted = array_values(array_filter(
        $classes,
        fn (string $class): bool => ! in_array($class, $allowed, true) && ! setsQueueFromQueues($class),
    ));

    expect($unrouted)->toBe([], 'These queueable classes do not set their queue from App\Support\Queues, so they would land on default: '.implode(', ', $unrouted));
});

it('puts the image job on the images queue', function (): void {
    expect((new SyncProductImages(1))->queue)->toBe(Queues::IMAGES)
        ->and(Queues::IMAGES)->toBe('images');
});

it('sends queued media conversions to the conversions queue', function (): void {
    expect(config('media-library.queue_name'))->toBe(Queues::CONVERSIONS)
        ->and(Queues::CONVERSIONS)->toBe('conversions');
});

it('runs one Horizon supervisor per queue with the agreed processes, timeouts and memory', function (): void {
    $supervisors = horizonSupervisors('production');

    $expected = [
        'supervisor-default' => ['queue' => ['default'], 'maxProcesses' => 3, 'timeout' => 60, 'memory' => 256],
        'supervisor-images' => ['queue' => ['images'], 'maxProcesses' => 2, 'timeout' => 960, 'memory' => 512],
        'supervisor-conversions' => ['queue' => ['conversions'], 'maxProcesses' => 4, 'timeout' => 300, 'memory' => 512],
    ];

    expect(array_keys($supervisors))->toBe(array_keys($expected));

    foreach ($expected as $name => $values) {
        foreach ($values as $key => $value) {
            expect($supervisors[$name][$key])->toBe($value, "{$name} {$key}");
        }

        expect($supervisors[$name]['connection'])->toBe('redis')
            ->and($supervisors[$name]['balance'])->toBeFalse()
            ->and($supervisors[$name]['maxJobs'])->toBeGreaterThan(0)
            ->and($supervisors[$name]['maxTime'])->toBeGreaterThan(0);
    }
});

it('has a long-wait threshold for every queue', function (): void {
    foreach (['default', 'images', 'conversions'] as $queue) {
        expect(config('horizon.waits'))->toHaveKey("redis:{$queue}");
    }
});

it('sets the image supervisor timeout above the image job timeout', function (): void {
    $jobTimeout = (new ReflectionClass(SyncProductImages::class))->getProperty('timeout')->getDefaultValue();

    expect(horizonSupervisors('production')['supervisor-images']['timeout'])->toBeGreaterThan($jobTimeout)
        ->and((new ReflectionClass(SyncProductImages::class))->getProperty('tries')->getDefaultValue())->toBe(1);
});

it('leaves the image job time for at least one worst-case download after its margin', function (): void {
    $timeout = (new ReflectionClass(SyncProductImages::class))->getProperty('timeout')->getDefaultValue();
    $budget = $timeout - (int) config('vendorsync.images.job_margin_seconds');
    $downloader = app(ImageDownloader::class);

    // Both URL shapes: https (one candidate) and http (https first, then http).
    expect($budget)->toBeGreaterThan($downloader->worstCaseDownloadSeconds('http://images.example.test/a.jpg'))
        ->and($budget)->toBeGreaterThan($downloader->worstCaseDownloadSeconds('https://images.example.test/a.jpg'));
});

it('serves every queue name with exactly one production supervisor', function (): void {
    $supervisors = horizonSupervisors('production');

    foreach ([Queues::DEFAULT, Queues::IMAGES, Queues::CONVERSIONS] as $queue) {
        $serving = array_keys(array_filter($supervisors, fn (array $supervisor): bool => in_array($queue, $supervisor['queue'], true)));

        expect($serving)->toHaveCount(1, "queue {$queue} must be served by exactly one supervisor");
    }

    foreach ($supervisors as $name => $supervisor) {
        expect($supervisor['queue'])->toHaveCount(1, "{$name} serves one queue");
    }
});

it('ties the image job to the supervisor that serves its queue, and that supervisor to retry_after', function (): void {
    $job = new SyncProductImages(1);
    $jobTimeout = (new ReflectionClass(SyncProductImages::class))->getProperty('timeout')->getDefaultValue();

    // The queue the job asks for is served by a supervisor whose timeout is
    // above the job's own, and the connection's retry_after is above that.
    $serving = array_filter(horizonSupervisors('production'), fn (array $supervisor): bool => in_array($job->queue, $supervisor['queue'], true));

    expect($serving)->toHaveCount(1);

    $supervisor = array_values($serving)[0];

    expect($job->queue)->toBe(Queues::IMAGES)
        ->and($supervisor['timeout'])->toBeGreaterThan($jobTimeout);

    foreach (['redis', 'database'] as $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))
            ->toBeGreaterThan($supervisor['timeout'], "{$connection} retry_after must exceed the {$job->queue} supervisor timeout");
    }

    // The supervisor works the connection the production queue runs on.
    expect($supervisor['connection'])->toBe('redis');
});

it('keeps retry_after above every job and supervisor timeout on each queue connection', function (): void {
    $longest = max(
        [...array_values(appJobTimeouts()), ...array_column(horizonSupervisors('production'), 'timeout')],
    );

    expect(appJobTimeouts())->toHaveKey(SyncProductImages::class);

    foreach (['redis', 'database'] as $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))
            ->toBeGreaterThan($longest, "{$connection} retry_after must exceed {$longest}");
    }
});
