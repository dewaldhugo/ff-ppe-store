<?php

declare(strict_types=1);

namespace App\Domain\Enquiries;

/** How emailing the store's staff went: see StaffMail. */
enum StaffMailOutcome
{
    /** It reached at least one person. */
    case Sent;

    /** No valid recipient is set, so nothing was sent. */
    case NoRecipient;

    /** Every send failed. */
    case Failed;
}
