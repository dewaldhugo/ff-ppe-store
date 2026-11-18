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
            'first_name', 'last_name', 'company_name', 'address_line_one', 'address_line_two', 'address_city',
            'address_county', 'address_postcode', 'industry', 'vat_number', 'phone', 'email', 'password',
        ]));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // Straight to the shop. With verification on, the banner there says to check the email; the
        // storefront and its prices work unverified, and only My Account asks for the verified address.
        return redirect()->route('shop')->with('status', User::verificationRequired()
            ? 'Your account has been created. We have emailed you a link to verify your address.'
            : 'Your account has been created.');
    }
}
