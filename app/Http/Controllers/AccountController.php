<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\UpdateCustomerProfile;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * My Account: contact and company details, and the password. The customer is always the
 * signed-in `web` user: no request carries a user or customer id, so nobody can edit another account.
 */
final class AccountController extends Controller
{
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user('web');

        return view('account.show', [
            'user' => $user,
            'customer' => $user->latestCustomer(),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request, UpdateCustomerProfile $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user('web');

        $emailChanged = $update($user, $request->safe()->only([
            'first_name', 'last_name', 'company_name', 'vat_number', 'phone', 'email',
        ]));

        if ($emailChanged && User::verificationRequired()) {
            return redirect()->route('verification.notice')
                ->with('status', 'Your details are saved. We have sent a link to your new email address: open it to verify it.');
        }

        return redirect()->route('account')->with('status', 'Your details have been saved.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user('web');

        $user->forceFill([
            'password' => $request->validated('password'),
            // A new remember token ends "keep me logged in" on every device.
            'remember_token' => Str::random(60),
        ])->save();

        $request->session()->regenerate();

        return redirect()->route('account')->with('status', 'Your password has been changed.');
    }
}
