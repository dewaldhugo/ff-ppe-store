<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Illuminate\Support\Facades\Auth;

/**
 * Prices are shown to signed-in customers only. This is the one definition.
 *
 * It reads the `web` guard, like the header does: a staff member signed in to
 * the Lunar admin is a guest on the storefront and does not see prices there.
 * Email verification does not matter here; being signed in is enough.
 */
final class PriceVisibility
{
    public function visible(): bool
    {
        return Auth::guard('web')->check();
    }
}
