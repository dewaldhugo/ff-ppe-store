<?php

declare(strict_types=1);

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

function accountCustomer(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'jo@builder.example',
        'password' => 'a-good-password-1',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function accountCredentials(array $overrides = []): array
{
    return array_merge(['email' => 'jo@builder.example', 'password' => 'a-good-password-1'], $overrides);
}

it('shows the log in form', function (): void {
    $html = $this->get(route('login'))->assertOk()->getContent();

    expect($html)
        ->toContain('name="email"')
        ->toContain('name="password"')
        ->toContain('name="remember"')
        ->toContain('action="'.route('login').'"')
        ->toContain('href="'.route('register').'"')
        ->toContain('<meta name="robots" content="noindex">');
});

it('logs a customer in on the web guard only and sends them to the shop', function (): void {
    $user = accountCustomer();

    $this->post(route('login'), accountCredentials())->assertRedirect(route('shop'));

    $this->assertAuthenticatedAs($user, 'web');
    $this->assertGuest('staff');
});

it('regenerates the session id on login', function (): void {
    accountCustomer();

    $this->get(route('login'));
    $before = session()->getId();

    $this->post(route('login'), accountCredentials());

    expect(session()->getId())->not->toBe($before);
});

it('sends the customer back to the page they were trying to reach', function (): void {
    accountCustomer();

    $this->withSession(['url.intended' => route('shop')])
        ->post(route('login'), accountCredentials())
        ->assertRedirect(route('shop'));
});

it('matches the email address whatever its case', function (): void {
    accountCustomer();

    $this->post(route('login'), accountCredentials(['email' => ' Jo@Builder.EXAMPLE ']))->assertRedirect(route('shop'));

    $this->assertAuthenticated('web');
});

it('sets the remember cookie only when asked', function (): void {
    accountCustomer();

    $with = $this->post(route('login'), accountCredentials(['remember' => '1']));
    expect(collect($with->headers->getCookies())->contains(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_')))->toBeTrue();

    auth('web')->logout();

    $without = $this->post(route('login'), accountCredentials());
    expect(collect($without->headers->getCookies())->contains(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_')))->toBeFalse();
});

it('gives the same error for a wrong password and an unknown email, and logs nobody in', function (): void {
    accountCustomer();

    $wrongPassword = $this->from(route('login'))->post(route('login'), accountCredentials(['password' => 'not-the-password-1']));
    $unknownEmail = $this->from(route('login'))->post(route('login'), accountCredentials(['email' => 'nobody@builder.example']));

    $wrongPassword->assertRedirect(route('login'))->assertSessionHasErrors(['email' => LoginRequest::FAILED]);
    $unknownEmail->assertRedirect(route('login'))->assertSessionHasErrors(['email' => LoginRequest::FAILED]);

    expect(session('errors')->getBag('default')->toArray())->toBe(['email' => [LoginRequest::FAILED]]);
    $this->assertGuest('web');
});

it('does not repeat the password on the form after a failed log in', function (): void {
    accountCustomer();

    $html = $this->followingRedirects()->from(route('login'))
        ->post(route('login'), accountCredentials(['password' => 'not-the-password-1']))
        ->getContent();

    expect($html)->toContain('value="jo@builder.example"')->not->toContain('not-the-password-1');
});

it('spends the time of a password hash on an unknown email', function (): void {
    Hash::partialMock()->shouldReceive('make')->once()->andReturn('hash');

    $this->post(route('login'), accountCredentials(['email' => 'nobody@builder.example']))->assertSessionHasErrors('email');
});

it('locks an email and IP out after 5 failed attempts, even for the right password', function (): void {
    Event::fake([Lockout::class]);
    accountCustomer();

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $attempt) {
        $this->post(route('login'), accountCredentials(['password' => 'wrong-password-'.$attempt]))
            ->assertSessionHasErrors(['email' => LoginRequest::FAILED]);
    }

    $this->post(route('login'), accountCredentials())->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toStartWith('Too many log in attempts. Try again in ');
    $this->assertGuest('web');
    Event::assertDispatched(Lockout::class);
});

it('does not lock out another email address from the same IP', function (): void {
    accountCustomer();
    accountCustomer(['email' => 'sam@builder.example']);

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $attempt) {
        $this->post(route('login'), accountCredentials(['password' => 'wrong-password-'.$attempt]));
    }

    $this->post(route('login'), accountCredentials(['email' => 'sam@builder.example']))->assertRedirect(route('shop'));
    $this->assertAuthenticated('web');
});

it('forgets the failed attempts after a successful log in', function (): void {
    accountCustomer();

    foreach (range(1, LoginRequest::MAX_ATTEMPTS - 1) as $attempt) {
        $this->post(route('login'), accountCredentials(['password' => 'wrong-password-'.$attempt]));
    }

    $this->post(route('login'), accountCredentials())->assertRedirect(route('shop'));
    auth('web')->logout();

    foreach (range(1, LoginRequest::MAX_ATTEMPTS - 1) as $attempt) {
        $this->post(route('login'), accountCredentials(['password' => 'wrong-password-'.$attempt]))
            ->assertSessionHasErrors(['email' => LoginRequest::FAILED]);
    }
});

it('answers 429 to the 21st log in request a minute from one IP', function (): void {
    foreach (range(1, 20) as $attempt) {
        $this->post(route('login'), accountCredentials(['email' => 'person'.$attempt.'@builder.example']))->assertRedirect();
    }

    $this->post(route('login'), accountCredentials())->assertStatus(429);
});

it('lets an unverified customer log in', function (): void {
    accountCustomer(['email_verified_at' => null]);

    $this->post(route('login'), accountCredentials())->assertRedirect(route('shop'));

    $this->assertAuthenticated('web');
});

it('sends a signed-in customer away from the log in page', function (): void {
    $this->actingAs(accountCustomer(), 'web')->get(route('login'))->assertRedirect(route('account'));
});

it('logs out with a POST, goes home and regenerates the session and token', function (): void {
    $this->actingAs(accountCustomer(), 'web');

    $this->get(route('home'));
    $sessionBefore = session()->getId();
    $tokenBefore = csrf_token();

    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest('web');
    expect(session()->getId())->not->toBe($sessionBefore)
        ->and(csrf_token())->not->toBe($tokenBefore);
});

it('does not log out on a GET', function (): void {
    $this->actingAs(accountCustomer(), 'web')->get('/logout')->assertStatus(405);

    $this->assertAuthenticated('web');
});

it('sends a guest who logs out to the home page without an error', function (): void {
    $this->post(route('logout'))->assertRedirect(route('home'));
});

it('lets the customer try again once the lockout has passed', function (): void {
    accountCustomer();

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $attempt) {
        $this->post(route('login'), accountCredentials(['password' => 'wrong-password-'.$attempt]));
    }

    $this->travel(2)->minutes();

    $this->post(route('login'), accountCredentials())->assertRedirect(route('shop'));
});
