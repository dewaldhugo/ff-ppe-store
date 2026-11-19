<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\CustomerVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Lunar\Admin\Models\Staff;

beforeEach(function (): void {
    config()->set('store.noindex', true);
    config()->set('store.require_email_verification', true);
});

function unverifiedCustomer(): User
{
    return User::factory()->unverified()->create(['email' => 'jo@builder.example']);
}

function verificationUrl(User $user, ?string $hash = null, ?int $id = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $id ?? $user->getKey(),
        'hash' => $hash ?? sha1($user->getEmailForVerification()),
    ]);
}

it('sends no verification link at registration: activating the login marks the address verified', function (): void {
    Notification::fake();

    $this->post(route('register'), [
        'first_name' => 'Jo',
        'last_name' => 'Builder',
        'company_name' => 'Builder Ltd',
        'address_line_one' => '1 High Street',
        'address_city' => 'Croydon',
        'address_postcode' => 'CR0 1XX',
        'industry' => 'construction',
        'email' => 'jo@builder.example',
        'password' => 'a-good-password-1',
        'password_confirmation' => 'a-good-password-1',
    ])->assertRedirect(route('home'));

    $user = User::firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertNotSentTo($user, CustomerVerifyEmail::class);
});

it('shows the verify page with the address and a resend button, noindex', function (): void {
    $html = $this->actingAs(unverifiedCustomer(), 'web')->get(route('verification.notice'))->assertOk()->getContent();

    expect($html)->toContain('jo@builder.example')
        ->toContain('action="'.route('verification.send').'"')
        ->toContain('action="'.route('logout').'"')
        ->toContain('<meta name="robots" content="noindex">');
});

it('sends a verified customer from the verify page to My Account', function (): void {
    $this->actingAs(User::factory()->create(), 'web')->get(route('verification.notice'))->assertRedirect(route('account'));
});

it('sends everyone from the verify page to My Account when verification is off', function (): void {
    config()->set('store.require_email_verification', false);

    $this->actingAs(unverifiedCustomer(), 'web')->get(route('verification.notice'))->assertRedirect(route('account'));
});

it('sends a guest from the verify page to log in', function (): void {
    $this->get(route('verification.notice'))->assertRedirect(route('login'));
});

it('verifies the address through the signed link', function (): void {
    Event::fake([Verified::class]);
    $user = unverifiedCustomer();

    $this->actingAs($user, 'web')->get(verificationUrl($user))
        ->assertRedirect(route('account'))
        ->assertSessionHas('status', 'Your email address has been verified.');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

it('verifies through the link in the actual email', function (): void {
    $user = unverifiedCustomer();
    $url = (new CustomerVerifyEmail)->toMail($user)->actionUrl;

    expect($url)->toContain('/email/verify/'.$user->getKey().'/'.sha1($user->email));

    $this->actingAs($user, 'web')->get($url)->assertRedirect(route('account'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('words the verification email in UK English with the store name', function (): void {
    config()->set('store.name', 'Acme PPE');

    $mail = (new CustomerVerifyEmail)->toMail(unverifiedCustomer());

    expect($mail->subject)->toBe('Verify your email address')
        ->and(implode(' ', $mail->introLines))->toContain('Acme PPE')
        ->and($mail->actionText)->toBe('Verify email address');
});

it('refuses a tampered, unsigned, expired or another customer\'s link', function (): void {
    $user = unverifiedCustomer();
    $other = User::factory()->unverified()->create();

    $unsigned = route('verification.verify', ['id' => $user->getKey(), 'hash' => sha1($user->email)]);
    $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->getKey(), 'hash' => sha1($user->email)]);

    $this->actingAs($user, 'web');

    $this->get($unsigned)->assertForbidden();
    $this->get($expired)->assertForbidden();
    $this->get(verificationUrl($user, hash: sha1('someone-else@example.test')))->assertForbidden();
    $this->get(verificationUrl($other))->assertForbidden();
    $this->get(str_replace('signature=', 'signature=0', verificationUrl($user)))->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('sends a guest who opens the link to log in, then back to the link', function (): void {
    $user = unverifiedCustomer();
    $url = verificationUrl($user);

    $this->get($url)->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe($url);
});

it('resends the link, and only to an unverified customer', function (): void {
    Notification::fake();
    $user = unverifiedCustomer();

    $this->actingAs($user, 'web')->from(route('verification.notice'))->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'We have sent a new verification link to your email address.');

    Notification::assertSentTo($user, CustomerVerifyEmail::class);

    $verified = User::factory()->create();
    $this->actingAs($verified, 'web')->post(route('verification.send'))->assertRedirect(route('account'));
    Notification::assertNotSentTo($verified, CustomerVerifyEmail::class);
});

it('sends no link when verification is off', function (): void {
    config()->set('store.require_email_verification', false);
    Notification::fake();

    $this->actingAs(unverifiedCustomer(), 'web')->post(route('verification.send'))->assertRedirect(route('account'));

    Notification::assertNothingSent();
});

it('allows 3 resends in 10 minutes, then answers 429', function (): void {
    Notification::fake();
    $this->actingAs(unverifiedCustomer(), 'web');

    foreach (range(1, 3) as $i) {
        $this->post(route('verification.send'))->assertRedirect();
    }

    $this->post(route('verification.send'))->assertStatus(429);
    Notification::assertSentTimes(CustomerVerifyEmail::class, 3);
});

it('does not break the page when the link cannot be sent', function (): void {
    $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('Mailer is down')));

    $this->actingAs(unverifiedCustomer(), 'web')->from(route('verification.notice'))->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'We could not send the email just now. Please try again in a few minutes.');
});

describe('the account.verified gate', function (): void {
    beforeEach(function (): void {
        Route::middleware(['web', 'auth:web', 'account.verified'])->get('/_test/gated', fn (): string => 'gated page')->name('test.gated');
    });

    it('lets a verified customer through', function (): void {
        $this->actingAs(User::factory()->create(), 'web')->get('/_test/gated')->assertOk()->assertSee('gated page');
    });

    it('sends an unverified customer to the verify page', function (): void {
        $this->actingAs(unverifiedCustomer(), 'web')->get('/_test/gated')->assertRedirect(route('verification.notice'));
    });

    it('answers 403 to an unverified customer who wants JSON', function (): void {
        $this->actingAs(unverifiedCustomer(), 'web')->getJson('/_test/gated')->assertForbidden();
    });

    it('lets an unverified customer through when verification is off', function (): void {
        config()->set('store.require_email_verification', false);

        $this->actingAs(unverifiedCustomer(), 'web')->get('/_test/gated')->assertOk();
    });

    it('sends a guest to log in', function (): void {
        $this->get('/_test/gated')->assertRedirect(route('login'));
    });

    it('does not treat a staff member as a customer', function (): void {
        $this->actingAs(Staff::factory()->create(), 'staff')->get('/_test/gated')->assertRedirect(route('login'));
    });
});

it('reads the switch from STORE_REQUIRE_EMAIL_VERIFICATION, true unless it is a false value', function (): void {
    // env() reads Laravel's env repository, which a local .env also fills, so set and clear it there.
    $read = function (?string $value): bool {
        $repository = Illuminate\Support\Env::getRepository();
        $previous = $repository->get('STORE_REQUIRE_EMAIL_VERIFICATION');

        $value === null
            ? $repository->clear('STORE_REQUIRE_EMAIL_VERIFICATION')
            : $repository->set('STORE_REQUIRE_EMAIL_VERIFICATION', $value);

        try {
            return (require config_path('store.php'))['require_email_verification'];
        } finally {
            $previous === null
                ? $repository->clear('STORE_REQUIRE_EMAIL_VERIFICATION')
                : $repository->set('STORE_REQUIRE_EMAIL_VERIFICATION', $previous);
        }
    };

    expect($read(null))->toBeTrue()
        ->and($read('true'))->toBeTrue()
        ->and($read('1'))->toBeTrue()
        ->and($read('false'))->toBeFalse()
        ->and($read('0'))->toBeFalse()
        ->and($read('off'))->toBeFalse();
});
