<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a customer out when staff have switched their login off while they were signed in.
 * Runs on every web request, but only does work when the `web` guard has a user. Like logout
 * (docs/accounts.md) it never invalidates the session: a staff member signed in to Lunar in
 * the same browser keeps their own session.
 */
final class EnsureLoginActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $request->hasSession() ? $guard->user() : null;

        if ($user instanceof User && ! $user->isApproved()) {
            $guard->logout();
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your login is not active. Please contact us.']);
        }

        return $next($request);
    }
}
