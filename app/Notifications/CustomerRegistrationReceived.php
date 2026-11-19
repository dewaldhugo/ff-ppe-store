<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queues;
use App\Support\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when someone registers: the login does not work until staff activate it, and this says
 * they will be told when that happens. Queued on the default queue.
 */
final class CustomerRegistrationReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue(Queues::DEFAULT);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We have received your registration')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Thank you for registering with '.Store::name().'.')
            ->line('We check every new account before it is activated. We will email you as soon as your login has been activated, and that email will tell you how to sign in.')
            ->line('If you did not register, no action is needed.');
    }
}
