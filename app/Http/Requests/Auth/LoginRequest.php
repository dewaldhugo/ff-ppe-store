<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginRequest extends FormRequest
{
    public const int MAX_ATTEMPTS = 5;

    /** The one message for an unknown email, a wrong password and an unusable account. */
    public const string FAILED = 'The email address or password is incorrect.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => 'email address'];
    }

    /**
     * Signs the customer in on the `web` guard, or throws one validation error that is the
     * same whether the email exists or not.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $guard = Auth::guard('web');
        $credentials = ['email' => Str::lower(trim((string) $this->input('email'))), 'password' => (string) $this->input('password')];

        if (! $guard->attempt($credentials, $this->boolean('remember'))) {
            // An unknown email never reaches a password check, which would make it answer
            // faster than a wrong password. Spend the same time hashing instead.
            Hash::make($credentials['password']);

            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => self::FAILED]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => 'Too many log in attempts. Try again in '.$seconds.' '.Str::plural('second', $seconds).'.',
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower(trim((string) $this->input('email'))).'|'.$this->ip());
    }
}
