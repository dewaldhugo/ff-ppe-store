<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Lunar\Admin\Models\Staff;

it('lets a Lunar staff member open Horizon', function (): void {
    $this->actingAs(Staff::factory()->create(), 'staff')
        ->get('/horizon')
        ->assertOk();
});

it('keeps guests out of Horizon', function (): void {
    $this->get('/horizon')->assertForbidden();
});

it('keeps a storefront customer out of Horizon', function (): void {
    $this->actingAs(User::factory()->create(), 'web')
        ->get('/horizon')
        ->assertForbidden();
});

it('leaves Horizon open in the local environment', function (): void {
    $this->app['env'] = 'local';

    $this->get('/horizon')->assertOk();
});

it('schedules the Horizon snapshot every five minutes in production only', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'horizon:snapshot'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->runsInEnvironment('production'))->toBeTrue()
        ->and($event->runsInEnvironment('local'))->toBeFalse();
});
