<?php

declare(strict_types=1);

namespace App\Domain\Enquiries;

use App\Support\Store;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Emails a form submission (the Contact enquiry, a wear trial request) to the same people as a new order
 * (STORE_ORDER_NOTIFICATION_EMAILS), or to the enquiry address while none are set (Store::enquiryRecipients).
 *
 * One email for each person, not one with everyone in the To field: nobody sees the others' addresses, and a
 * failure for one does not stop the rest. The customer is told it failed only if it reached nobody; each
 * failure is reported.
 */
final class StaffMail
{
    /**
     * @param  Closure(): Mailable  $mailable  Builds the email; called once for each recipient.
     */
    public function send(Closure $mailable): StaffMailOutcome
    {
        $recipients = Store::enquiryRecipients();

        if ($recipients === []) {
            report(new RuntimeException('No valid enquiry recipient: set STORE_ORDER_NOTIFICATION_EMAILS, or store.enquiry_email in config/store.php.'));

            return StaffMailOutcome::NoRecipient;
        }

        $delivered = 0;

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send($mailable());

                $delivered++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $delivered === 0 ? StaffMailOutcome::Failed : StaffMailOutcome::Sent;
    }
}
