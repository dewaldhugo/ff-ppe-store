<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

final class EmailVerificationController extends Controller
{
    /**
     * Tells an unverified customer to check their email, and offers to send the link again.
     * Anyone who does not need to verify goes straight to My Account.
     */
    public function notice(Request $request): View|RedirectResponse
    {
        $user = $this->customer($request);

        if (! User::verificationRequired() || $user->hasVerifiedEmail()) {
            return redirect()->route('account');
        }

        return view('auth.verify-email', ['email' => $user->email]);
    }

    /**
     * The signed link in the email. EmailVerificationRequest checks the id and hash against
     * the signed-in customer, and the `signed` middleware checks the signature and expiry.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('account')->with('status', 'Your email address has been verified.');
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $this->customer($request);

        if (! User::verificationRequired() || $user->hasVerifiedEmail()) {
            return redirect()->route('account');
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            report($e);

            return back()->with('status', 'We could not send the email just now. Please try again in a few minutes.');
        }

        return back()->with('status', 'We have sent a new verification link to your email address.');
    }

    private function customer(Request $request): User
    {
        $user = Auth::guard('web')->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
