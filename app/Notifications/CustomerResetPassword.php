<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Queues;
use App\Support\Store;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The password reset email, queued on the default queue (never images or conversions).
 */
final class CustomerResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] $token)
    {
        parent::__construct($token);

        $this->onQueue(Queues::DEFAULT);
    }

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Reset your password')
            ->greeting('Hello,')
            ->line('We received a request to reset the password for your '.Store::name().' account.')
            ->action('Reset password', $url)
            ->line('This link expires in '.$minutes.' minutes.')
            ->line('If you did not ask for a reset, no further action is needed.');
    }
}
