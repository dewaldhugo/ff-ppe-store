<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request, RegisterCustomer $register): RedirectResponse
    {
        // The honeypot is filled only by bots. Pretend it worked and create nothing.
        if ($request->filled('website')) {
            return redirect()->route('home')->with('status', 'Thank you for registering.');
        }

        $user = $register($request->safe()->only([
            'first_name', 'last_name', 'company_name', 'vat_number', 'phone', 'email', 'password',
        ]));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // With verification on, the new customer is asked to check their email first.
        if (User::verificationRequired()) {
            return redirect()->route('verification.notice')->with('status', 'Your account has been created.');
        }

        return redirect()->route('account')->with('status', 'Your account has been created.');
    }
}
