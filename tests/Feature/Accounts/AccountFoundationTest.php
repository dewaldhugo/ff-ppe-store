<?php

declare(strict_types=1);

use App\Domain\Accounts\RateLimits;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

it('requires email verification by default', function (): void {
    // The default in the file, whatever a local .env sets (it may switch verification off while there is no mailer).
    expect(file_get_contents(config_path('store.php')))->toContain("env('STORE_REQUIRE_EMAIL_VERIFICATION', true)");
});

it('adds a nullable phone column to users', function (): void {
    expect(Schema::hasColumn('users', 'phone'))->toBeTrue();

    $user = User::factory()->create(['phone' => null]);
    expect($user->fresh()->phone)->toBeNull();

    $user->update(['phone' => '020 8289 5858']);
    expect($user->fresh()->phone)->toBe('020 8289 5858');
});

it('registers every named account rate limiter', function (): void {
    foreach (['login', 'register', 'password-email', 'password-reset', 'verification-send'] as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull($name);
    }
});

it('lets 10 registrations an hour through per IP', function (): void {
    $limit = RateLimiter::limiter('register')(Request::create('/register', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(10)
        ->and($limit->decaySeconds)->toBe(3600)
        ->and($limit->key)->toBe('203.0.113.9')
        ->and(RateLimits::REGISTER_PER_HOUR)->toBe(10);
});

it('limits password reset emails per IP and per address', function (): void {
    $limits = RateLimiter::limiter('password-email')(Request::create('/forgot-password', 'POST', ['email' => 'Buyer@Example.com'], server: ['REMOTE_ADDR' => '203.0.113.9']));

    expect($limits)->toHaveCount(2)
        ->and($limits[0]->maxAttempts)->toBe(5)
        ->and($limits[1]->maxAttempts)->toBe(3)
        ->and($limits[1]->key)->toBe('address:buyer@example.com');
});

it('sets the default password rules', function (): void {
    $rules = Validator::make(['password' => 'short1'], ['password' => Password::defaults()]);
    expect($rules->fails())->toBeTrue();

    expect(Validator::make(['password' => 'onlyletterspassword'], ['password' => Password::defaults()])->fails())->toBeTrue()
        ->and(Validator::make(['password' => 'a-good-password-1'], ['password' => Password::defaults()])->fails())->toBeFalse();
});
