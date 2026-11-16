<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls\Redirects;

enum RedirectOutcome
{
    case Recorded;
    case Unchanged;
    case Refused;
}
