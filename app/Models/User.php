<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\CustomerResetPassword;
use App\Notifications\CustomerVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lunar\Base\LunarUser as LunarUserInterface;
use Lunar\Base\Traits\LunarUser;

/**
 * A storefront customer login. Staff are a different model and a different guard
 * (Lunar\Models\Staff on the `staff` guard); a User is never a staff member.
 *
 * `name` is the full name ("First Last"). The split names and the company details live on
 * the Lunar Customer attached through `customers()`.
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable implements LunarUserInterface, MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LunarUser, Notifiable;

    /**
     * Whether this customer must verify their email address before using My Account.
     * Switched by STORE_REQUIRE_EMAIL_VERIFICATION.
     */
    public static function verificationRequired(): bool
    {
        return (bool) config('store.require_email_verification');
    }

    /**
     * Sends nothing when verification is switched off. The framework fires this from the
     * Registered event, so the switch is honoured in one place.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (! self::verificationRequired()) {
            return;
        }

        $this->notify(new CustomerVerifyEmail);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new CustomerResetPassword($token));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
