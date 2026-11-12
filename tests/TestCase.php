<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the foundation data and the fixture catalogue once per test run,
     * straight after the database is migrated. Each test then runs inside a
     * transaction on top of that data.
     */
    protected bool $seed = true;
}
