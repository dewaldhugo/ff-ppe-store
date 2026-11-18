<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

final class PasswordResetLinkController extends Controller
{
    /** The one answer, whether or not an account exists, the broker throttled the request or the mailer failed. */
    public const string SENT = 'If an account exists for that email address, we have sent a link to reset the password.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        try {
            // Only the `users` broker: staff accounts cannot be reset from the storefront.
            Password::broker('users')->sendResetLink(['email' => $request->validated('email')]);
        } catch (Throwable $e) {
            // A queue or mail failure must not answer differently from an unknown email.
            report($e);
        }

        return back()->with('status', self::SENT);
    }
}
