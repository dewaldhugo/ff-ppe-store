<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // A new session id on every login stops session fixation.
        $request->session()->regenerate();

        // To the shop, unless the customer was sent to log in from a page that needs it.
        return redirect()->intended(route('shop'));
    }

    /**
     * Logs the customer out of the `web` guard only. Staff sign in to Lunar on the `staff`
     * guard and share this session store, so the session is regenerated, never invalidated:
     * invalidating would end a staff member's admin session in the same browser.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
