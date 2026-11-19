<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
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

        $register($request->safe()->only([
            'first_name', 'last_name', 'company_name', 'address_line_one', 'address_line_two', 'address_city',
            'address_county', 'address_postcode', 'industry', 'vat_number', 'phone', 'email', 'password',
        ]));

        // Not signed in: staff must activate the login first, and the customer is emailed when they do.
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'Your registration was successful. We will contact you shortly.');
    }
}
