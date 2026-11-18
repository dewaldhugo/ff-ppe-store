<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queues;
use App\Support\Store;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The verification email, queued on the default queue (never images or conversions).
 */
final class CustomerVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue(Queues::DEFAULT);
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your email address')
            ->greeting('Hello,')
            ->line('Thank you for registering with '.Store::name().'. Please confirm your email address to use your account.')
            ->action('Verify email address', $url)
            ->line('If you did not register, no further action is needed.');
    }
}
