<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a customer with an unverified email address out of My Account while
 * STORE_REQUIRE_EMAIL_VERIFICATION is on. With the switch off it lets everyone through.
 *
 * Only the `web` guard is looked at: a staff member is never a customer. Put it after `auth:web`.
 */
final class EnsureAccountVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! User::verificationRequired()) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? abort(403, 'Your email address is not verified.')
                : redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
