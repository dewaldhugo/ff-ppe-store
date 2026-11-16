<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Notifications;

use App\Domain\VendorSync\Models\SyncRun;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mailed when a vendor sync run fails or is aborted.
 */
final class SyncRunAlert extends Notification
{
    public function __construct(public readonly SyncRun $run) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->run->loadMissing('vendor');

        $held = (int) $run->held_prices;
        $heldCurrency = (int) $run->held_currency;
        $heldOnly = $run->status === 'succeeded' && ($held > 0 || $heldCurrency > 0);

        $what = array_filter([
            $held > 0 ? "{$held} price ".($held === 1 ? 'change' : 'changes') : null,
            $heldCurrency > 0 ? "{$heldCurrency} currency ".($heldCurrency === 1 ? 'flip' : 'flips') : null,
        ]);

        $mail = (new MailMessage)
            ->error()
            ->subject($heldOnly
                ? 'Vendor sync held '.implode(' and ', $what).": {$run->vendor->name} {$run->type}"
                : "Vendor sync {$run->status}: {$run->vendor->name} {$run->type}")
            ->line("The {$run->type} run for {$run->vendor->name} ({$run->vendor->code}) {$run->status}.")
            ->line("Run {$run->id}, started {$run->started_at?->timezone('Europe/London')->format('d/m/Y H:i')}.")
            ->line("Seen {$run->seen}, created {$run->created}, updated {$run->updated}, unchanged {$run->unchanged}, drafted {$run->drafted}, errors {$run->errors}.");

        if ($held > 0) {
            $mail->line("Held price changes: {$held}. Their cost, price and compare price were kept. The full list is in {$run->report_path}.");
        }

        if ($heldCurrency > 0) {
            $mail->line("Held currency flips: {$heldCurrency}. The vendor answered in a currency other than GBP, so the stored GBP cost, price, compare price and availability were kept. Nothing was converted. The full list, with when the stored values were last written, is in {$run->report_path}.");
        }

        foreach (array_slice($run->messages ?? [], 0, 10) as $message) {
            $mail->line($message);
        }

        return $mail->line('Run `php artisan vendorsync:status` for the latest state.');
    }
}
