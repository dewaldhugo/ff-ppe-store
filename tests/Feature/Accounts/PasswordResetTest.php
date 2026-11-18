<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\User;
use App\Notifications\CustomerResetPassword;
use App\Support\Queues;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Lunar\Admin\Models\Staff;

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

function requestResetFor(string $email): TestResponse
{
    return test()->from(route('password.request'))->post(route('password.email'), ['email' => $email]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function resetPayload(string $token, array $overrides = []): array
{
    return array_merge([
        'token' => $token,
        'email' => 'jo@builder.example',
        'password' => 'a-brand-new-password-2',
        'password_confirmation' => 'a-brand-new-password-2',
    ], $overrides);
}

it('links to the forgot password page from the log in page', function (): void {
    $html = $this->get(route('login'))->getContent();

    expect($html)->toContain('href="'.route('password.request').'"');
});

it('shows the forgot password and reset forms, noindex', function (): void {
    $forgot = $this->get(route('password.request'))->assertOk()->getContent();
    $reset = $this->get(route('password.reset', ['token' => 'abc123', 'email' => 'jo@builder.example']))->assertOk()->getContent();

    expect($forgot)->toContain('action="'.route('password.email').'"')->toContain('name="email"')
        ->and($forgot)->toContain('<meta name="robots" content="noindex">')
        ->and($reset)->toContain('action="'.route('password.store').'"')
        ->toContain('name="token" value="abc123"')
        ->toContain('value="jo@builder.example"')
        ->toContain('name="password_confirmation"')
        ->toContain('<meta name="robots" content="noindex">');
});

it('queues a reset email for a registered address on the default queue', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'jo@builder.example']);

    requestResetFor('jo@builder.example')->assertRedirect(route('password.request'));

    Notification::assertSentTo($user, CustomerResetPassword::class, fn (CustomerResetPassword $n): bool => $n->queue === Queues::DEFAULT);
});

it('answers the same way for a registered and an unknown email, and sends nothing for the unknown one', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'jo@builder.example']);

    $known = requestResetFor('jo@builder.example');
    $unknown = requestResetFor('nobody@builder.example');

    $known->assertSessionHas('status', PasswordResetLinkController::SENT)->assertSessionHasNoErrors();
    $unknown->assertSessionHas('status', PasswordResetLinkController::SENT)->assertSessionHasNoErrors();
    expect($known->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($known->headers->get('Location'))->toBe($unknown->headers->get('Location'));
    Notification::assertSentTimes(CustomerResetPassword::class, 1);
});

it('answers the same way when the broker throttles a second request', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'jo@builder.example']);

    requestResetFor('jo@builder.example');
    $second = requestResetFor('jo@builder.example');

    $second->assertSessionHas('status', PasswordResetLinkController::SENT)->assertSessionHasNoErrors();
    Notification::assertSentTimes(CustomerResetPassword::class, 1);
});

it('answers the same way when the mailer or queue fails', function (): void {
    User::factory()->create(['email' => 'jo@builder.example']);
    $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('Queue is down')));

    requestResetFor('jo@builder.example')
        ->assertSessionHas('status', PasswordResetLinkController::SENT)
        ->assertSessionHasNoErrors();
});

it('does not send a reset email for a staff address', function (): void {
    Notification::fake();
    Staff::factory()->create(['email' => 'staff@example.test']);

    requestResetFor('staff@example.test')->assertSessionHas('status', PasswordResetLinkController::SENT);

    Notification::assertNothingSent();
});

it('rejects a malformed email address on the forgot password form', function (): void {
    $this->from(route('password.request'))->post(route('password.email'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');
});

it('resets the password through the emailed link, end to end', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'jo@builder.example', 'password' => 'the-old-password-1', 'remember_token' => 'old-token']);

    requestResetFor('jo@builder.example');

    $url = null;
    Notification::assertSentTo($user, CustomerResetPassword::class, function (CustomerResetPassword $notification) use ($user, &$url): bool {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    expect($url)->toContain('/reset-password/')
        ->and($url)->toContain('email=jo%40builder.example');

    $this->get($url)->assertOk();

    $token = basename((string) parse_url($url, PHP_URL_PATH));

    Event::fake([PasswordReset::class]);

    $this->post(route('password.store'), resetPayload($token))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your password has been reset. Log in with your new password.');

    $user->refresh();
    expect(Hash::check('a-brand-new-password-2', $user->password))->toBeTrue()
        ->and(Hash::check('the-old-password-1', $user->password))->toBeFalse()
        ->and($user->remember_token)->not->toBe('old-token');
    $this->assertGuest('web');
    Event::assertDispatched(PasswordReset::class);
});

it('lets the customer log in with the new password and not the old one', function (): void {
    $user = User::factory()->create(['email' => 'jo@builder.example', 'password' => 'the-old-password-1']);
    $token = Password::broker('users')->createToken($user);

    $this->post(route('password.store'), resetPayload($token));

    $this->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'the-old-password-1'])->assertSessionHasErrors('email');
    $this->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'a-brand-new-password-2'])->assertRedirect(route('shop'));
});

it('refuses a bad token, an unknown email and an expired token with one message', function (): void {
    $user = User::factory()->create(['email' => 'jo@builder.example', 'password' => 'the-old-password-1']);
    $token = Password::broker('users')->createToken($user);

    $badToken = $this->from(route('password.reset', 'x'))->post(route('password.store'), resetPayload('not-the-token'));
    $unknownEmail = $this->from(route('password.reset', 'x'))->post(route('password.store'), resetPayload($token, ['email' => 'nobody@builder.example']));
    $this->travel(61)->minutes();
    $expired = $this->from(route('password.reset', 'x'))->post(route('password.store'), resetPayload($token));

    foreach ([$badToken, $unknownEmail, $expired] as $response) {
        $response->assertSessionHasErrors(['email' => NewPasswordController::INVALID]);
    }

    expect(Hash::check('the-old-password-1', $user->fresh()->password))->toBeTrue();
});

it('lets a reset link be used once', function (): void {
    $user = User::factory()->create(['email' => 'jo@builder.example']);
    $token = Password::broker('users')->createToken($user);

    $this->post(route('password.store'), resetPayload($token))->assertRedirect(route('login'));

    $this->from(route('password.reset', $token))
        ->post(route('password.store'), resetPayload($token, ['password' => 'yet-another-password-3', 'password_confirmation' => 'yet-another-password-3']))
        ->assertSessionHasErrors(['email' => NewPasswordController::INVALID]);

    expect(Hash::check('a-brand-new-password-2', $user->fresh()->password))->toBeTrue();
});

it('applies the password rules on reset', function (array $overrides): void {
    $user = User::factory()->create(['email' => 'jo@builder.example', 'password' => 'the-old-password-1']);
    $token = Password::broker('users')->createToken($user);

    $this->from(route('password.reset', $token))->post(route('password.store'), resetPayload($token, $overrides))
        ->assertSessionHasErrors('password');

    expect(Hash::check('the-old-password-1', $user->fresh()->password))->toBeTrue();
})->with([
    'too short' => [['password' => 'abc123', 'password_confirmation' => 'abc123']],
    'letters only' => [['password' => 'onlyletterspassword', 'password_confirmation' => 'onlyletterspassword']],
    'not confirmed' => [['password_confirmation' => 'something-else-123']],
]);

it('allows 5 reset emails an hour per IP and 3 per address, then answers 429', function (): void {
    Notification::fake();

    foreach (range(1, 3) as $i) {
        requestResetFor('jo@builder.example')->assertSessionHas('status');
    }

    requestResetFor('jo@builder.example')->assertStatus(429);

    foreach (['a', 'b'] as $other) {
        requestResetFor($other.'@builder.example')->assertSessionHas('status');
    }

    requestResetFor('c@builder.example')->assertStatus(429);
});

it('allows 10 reset submissions an hour per IP, then answers 429', function (): void {
    foreach (range(1, 10) as $i) {
        $this->post(route('password.store'), resetPayload('not-the-token'))->assertRedirect();
    }

    $this->post(route('password.store'), resetPayload('not-the-token'))->assertStatus(429);
});

it('sends a signed-in customer away from the password pages', function (): void {
    $this->actingAs(User::factory()->create(), 'web');

    $this->get(route('password.request'))->assertRedirect(route('account'));
    $this->get(route('password.reset', 'abc'))->assertRedirect(route('account'));
});

it('words the reset email in UK English with the store name', function (): void {
    config()->set('store.name', 'Acme PPE');
    $user = User::factory()->create();

    $mail = (new CustomerResetPassword('a-token'))->toMail($user);

    expect($mail->subject)->toBe('Reset your password')
        ->and(implode(' ', $mail->introLines))->toContain('Acme PPE')
        ->and($mail->actionText)->toBe('Reset password')
        ->and($mail->actionUrl)->toContain('/reset-password/a-token');
});
