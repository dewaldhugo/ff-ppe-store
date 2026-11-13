<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pricing\PriceDisplay;
use App\Domain\Pricing\VatDisplayMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The inc/ex VAT toggle in the header. A plain form post, so it works
 * without JavaScript, then back to the page the customer was on.
 */
final class VatModeController extends Controller
{
    public function __invoke(Request $request, PriceDisplay $priceDisplay): RedirectResponse
    {
        $mode = VatDisplayMode::tryFrom((string) $request->input('mode'));

        if ($mode !== null) {
            $priceDisplay->setMode($mode);
        }

        return back();
    }
}
