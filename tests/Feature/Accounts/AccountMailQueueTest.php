<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\CustomerResetPassword;
use App\Notifications\CustomerVerifyEmail;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('queues the verification email on the default queue', function (): void {
    config()->set('store.require_email_verification', true);
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, CustomerVerifyEmail::class, function (CustomerVerifyEmail $notification): bool {
        return $notification instanceof ShouldQueue && $notification->queue === Queues::DEFAULT;
    });
});

it('queues the password reset email on the default queue', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $user->sendPasswordResetNotification('a-token');

    Notification::assertSentTo($user, CustomerResetPassword::class, function (CustomerResetPassword $notification): bool {
        return $notification instanceof ShouldQueue
            && $notification->queue === Queues::DEFAULT
            && $notification->token === 'a-token';
    });
});

it('pushes the account emails onto the default queue and never onto images or conversions', function (): void {
    config()->set('store.require_email_verification', true);
    Queue::fake();

    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();
    $user->sendPasswordResetNotification('a-token');

    Queue::assertPushed(SendQueuedNotifications::class, 2);
    Queue::assertPushedOn(Queues::DEFAULT, SendQueuedNotifications::class);

    $queues = collect(Queue::pushedJobs()[SendQueuedNotifications::class])->pluck('queue')->unique()->all();
    expect($queues)->toBe([Queues::DEFAULT]);
});

it('sends no verification email when verification is switched off', function (): void {
    config()->set('store.require_email_verification', false);
    Notification::fake();

    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    Notification::assertNothingSent();
});

it('still sends the password reset email when verification is switched off', function (): void {
    config()->set('store.require_email_verification', false);
    Notification::fake();

    $user = User::factory()->create();
    $user->sendPasswordResetNotification('a-token');

    Notification::assertSentTo($user, CustomerResetPassword::class);
});
