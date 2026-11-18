<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The named rate limiters behind the account routes (`throttle:<name>`).
 *
 * Login also has a stricter per email and IP limit inside LoginRequest, which can show
 * the customer how long to wait. These per-IP limits are the outer wall.
 */
final class RateLimits
{
    public const int LOGIN_PER_MINUTE = 20;

    /** Per IP. Shared office networks register several people from one address. */
    public const int REGISTER_PER_HOUR = 10;

    public const int PASSWORD_EMAIL_PER_HOUR_PER_IP = 5;

    public const int PASSWORD_EMAIL_PER_HOUR_PER_ADDRESS = 3;

    public const int PASSWORD_RESET_PER_HOUR = 10;

    public const int VERIFICATION_SEND_PER_10_MINUTES = 3;

    public static function register(): void
    {
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(self::LOGIN_PER_MINUTE)->by((string) $request->ip()));

        RateLimiter::for('register', fn (Request $request): Limit => Limit::perHour(self::REGISTER_PER_HOUR)->by((string) $request->ip()));

        RateLimiter::for('password-email', fn (Request $request): array => [
            Limit::perHour(self::PASSWORD_EMAIL_PER_HOUR_PER_IP)->by('ip:'.$request->ip()),
            Limit::perHour(self::PASSWORD_EMAIL_PER_HOUR_PER_ADDRESS)->by('address:'.Str::lower((string) $request->input('email'))),
        ]);

        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perHour(self::PASSWORD_RESET_PER_HOUR)->by((string) $request->ip()));

        RateLimiter::for('verification-send', fn (Request $request): Limit => Limit::perMinutes(10, self::VERIFICATION_SEND_PER_10_MINUTES)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
