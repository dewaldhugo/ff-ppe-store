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
 * Sent when staff activate a customer's login: the login URL and the username. Passwords are
 * stored hashed and are never emailed, so the message says to use the one chosen at
 * registration, and points at the password reset for anyone who has forgotten it.
 */
final class CustomerAccountActivated extends Notification implements ShouldQueue
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
            ->subject('Your '.Store::name().' login is now active')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Good news: your account has been activated and you can now sign in to see prices and place orders.')
            ->line('Login page: '.route('login'))
            ->line('Username: '.$notifiable->email)
            ->line('Password: the password you chose when you registered. For your security we never store or email passwords.')
            ->action('Log in', route('login'))
            ->line('If you have forgotten your password, you can reset it from the login page: '.route('password.request'));
    }
}
