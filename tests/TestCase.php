<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the foundation data and the fixture catalogue once per test run,
     * straight after the database is migrated. Each test then runs inside a
     * transaction on top of that data.
     */
    protected bool $seed = true;

    /**
     * Refuses to run against anything but the testing database. RefreshDatabase wipes and rebuilds the whole
     * database before every run, so a test run on the development database destroys its data. That happens when a
     * cached config (`php artisan optimize` or `config:cache`) or a real DB_DATABASE environment variable overrides
     * phpunit.xml. This runs right after the application is created, before anything touches the database.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');

        if (! $this->app->environment('testing') || ! (str_ends_with($database, '_testing') || $database === ':memory:')) {
            throw new RuntimeException(
                'Refusing to run the tests: they would wipe the database "'.$database.'" (environment "'.$this->app->environment().'"). '
                .'Tests must run in the "testing" environment on a database whose name ends in _testing. '
                .'A cached config usually causes this: run `php artisan optimize:clear`, and check that DB_DATABASE is not set in your shell.'
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Pages must render without built assets or a running dev server.
        $this->withoutVite();
    }
}
