<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backstop for category URL changes that fire no model event. Independent of
// VENDORSYNC_SCHEDULE_ENABLED: redirects matter whether or not ingestion runs.
Schedule::command('catalogue:sync-redirects')->hourly()->withoutOverlapping();
