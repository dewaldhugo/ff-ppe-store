<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\CustomerVerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Customer;
use Lunar\Models\CustomerGroup;

beforeEach(function (): void {
    config()->set('store.noindex', true);
    config()->set('store.require_email_verification', true);
    Notification::fake();
});

/**
 * A verified customer with a Lunar customer attached, like one who registered.
 *
 * @param  array<string, mixed>  $user
 * @param  array<string, mixed>  $customer
 */
function registeredCustomer(array $user = [], array $customer = []): User
{
    $account = User::factory()->create(array_merge([
        'name' => 'Jo Builder',
        'email' => 'jo@builder.example',
        'phone' => '020 8289 5858',
        'password' => 'the-old-password-1',
    ], $user));

    $account->customers()->attach(Customer::create(array_merge([
        'first_name' => 'Jo',
        'last_name' => 'Builder',
        'company_name' => 'Builder & Sons Ltd',
        'tax_identifier' => 'GB123456782',
    ], $customer)));

    return $account;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function profileInput(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Joanne',
        'last_name' => 'Builder-Smith',
        'company_name' => 'Smith Building Ltd',
        'vat_number' => 'gb 987 6543 21',
        'phone' => '07700 900123',
        'email' => 'jo@builder.example',
    ], $overrides);
}

it('keeps guests out of My Account and sends them to log in', function (): void {
    $this->get(route('account'))->assertRedirect(route('login'));
    $this->put(route('account.profile.update'), profileInput())->assertRedirect(route('login'));
    $this->put(route('account.password.update'), [])->assertRedirect(route('login'));
});

it('keeps a staff member out of My Account', function (): void {
    $this->actingAs(Staff::factory()->create(), 'staff');

    $this->get(route('account'))->assertRedirect(route('login'));
});

it('sends an unverified customer to the verify page while verification is on', function (): void {
    $this->actingAs(registeredCustomer(['email_verified_at' => null]), 'web');

    $this->get(route('account'))->assertRedirect(route('verification.notice'));
    $this->put(route('account.profile.update'), profileInput())->assertRedirect(route('verification.notice'));
});

it('lets an unverified customer in when verification is off', function (): void {
    config()->set('store.require_email_verification', false);

    $this->actingAs(registeredCustomer(['email_verified_at' => null]), 'web')->get(route('account'))->assertOk();
});

it('shows the customer their details, noindex, with a log out button', function (): void {
    $html = $this->actingAs(registeredCustomer(), 'web')->get(route('account'))->assertOk()->getContent();

    expect($html)
        ->toContain('<meta name="robots" content="noindex">')
        ->toContain('value="Jo"')
        ->toContain('value="Builder"')
        ->toContain('value="Builder &amp; Sons Ltd"')
        ->toContain('value="GB123456782"')
        ->toContain('value="020 8289 5858"')
        ->toContain('value="jo@builder.example"')
        ->toContain('action="'.route('account.profile.update').'"')
        ->toContain('action="'.route('account.password.update').'"')
        ->toContain('action="'.route('logout').'"')
        ->not->toContain('the-old-password-1');
});

it('updates the user and the Lunar customer', function (): void {
    $user = registeredCustomer();

    $this->actingAs($user, 'web')->put(route('account.profile.update'), profileInput())
        ->assertRedirect(route('account'))
        ->assertSessionHas('status', 'Your details have been saved.');

    $customer = $user->fresh()->latestCustomer();

    expect($user->fresh()->name)->toBe('Joanne Builder-Smith')
        ->and($user->fresh()->phone)->toBe('07700 900123')
        ->and($customer->first_name)->toBe('Joanne')
        ->and($customer->last_name)->toBe('Builder-Smith')
        ->and($customer->company_name)->toBe('Smith Building Ltd')
        ->and($customer->tax_identifier)->toBe('GB987654321');
    Notification::assertNothingSent();
});

it('clears an optional VAT number and phone', function (): void {
    $user = registeredCustomer();

    $this->actingAs($user, 'web')->put(route('account.profile.update'), profileInput(['vat_number' => '', 'phone' => '']))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->phone)->toBeNull()
        ->and($user->fresh()->latestCustomer()->tax_identifier)->toBeNull();
});

it('keeps the email verified and needs no password when the email is unchanged', function (): void {
    $user = registeredCustomer();

    $this->actingAs($user, 'web')->put(route('account.profile.update'), profileInput(['email' => 'JO@builder.example']))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Notification::assertNothingSent();
});

it('needs the current password to change the email address', function (?string $password): void {
    $user = registeredCustomer();

    $this->actingAs($user, 'web')->from(route('account'))
        ->put(route('account.profile.update'), profileInput(['email' => 'new@builder.example', 'confirm_password' => $password]))
        ->assertRedirect(route('account'))
        ->assertSessionHasErrors('confirm_password');

    expect($user->fresh()->email)->toBe('jo@builder.example')
        ->and($user->fresh()->name)->toBe('Jo Builder');
})->with([
    'no password' => [null],
    'wrong password' => ['not-the-password-1'],
]);

it('changes the email address, unverifies it and sends a new link', function (): void {
    $user = registeredCustomer();

    $this->actingAs($user, 'web')
        ->put(route('account.profile.update'), profileInput(['email' => 'New@Builder.example', 'confirm_password' => 'the-old-password-1']))
        ->assertRedirect(route('verification.notice'));

    expect($user->fresh()->email)->toBe('new@builder.example')
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user->fresh(), CustomerVerifyEmail::class);

    // The next page is the verify page, until they verify.
    $this->get(route('account'))->assertRedirect(route('verification.notice'));
});

it('changes the email address and goes back to My Account when verification is off', function (): void {
    config()->set('store.require_email_verification', false);
    $user = registeredCustomer();

    $this->actingAs($user, 'web')
        ->put(route('account.profile.update'), profileInput(['email' => 'new@builder.example', 'confirm_password' => 'the-old-password-1']))
        ->assertRedirect(route('account'));

    expect($user->fresh()->email)->toBe('new@builder.example');
    Notification::assertNothingSent();
});

it('refuses an email address another customer uses, but not the customer\'s own', function (): void {
    User::factory()->create(['email' => 'taken@builder.example']);
    $user = registeredCustomer();

    $this->actingAs($user, 'web')->from(route('account'))
        ->put(route('account.profile.update'), profileInput(['email' => 'taken@builder.example', 'confirm_password' => 'the-old-password-1']))
        ->assertSessionHasErrors(['email' => 'An account with this email address already exists.']);

    $this->put(route('account.profile.update'), profileInput(['email' => 'jo@builder.example']))->assertSessionHasNoErrors();
});

it('validates the details', function (array $overrides, string $field): void {
    $user = registeredCustomer();

    $this->actingAs($user, 'web')->from(route('account'))
        ->put(route('account.profile.update'), profileInput($overrides))
        ->assertRedirect(route('account'))
        ->assertSessionHasErrors($field);

    expect($user->fresh()->name)->toBe('Jo Builder')
        ->and($user->fresh()->latestCustomer()->company_name)->toBe('Builder & Sons Ltd');
})->with([
    'no first name' => [['first_name' => ''], 'first_name'],
    'no last name' => [['last_name' => ''], 'last_name'],
    'no company' => [['company_name' => ''], 'company_name'],
    'no email' => [['email' => ''], 'email'],
    'bad email' => [['email' => 'nope'], 'email'],
    'bad VAT number' => [['vat_number' => '12345'], 'vat_number'],
    'bad phone' => [['phone' => 'call me'], 'phone'],
]);

it('only ever edits the signed-in customer', function (): void {
    $other = registeredCustomer(['email' => 'other@builder.example', 'name' => 'Other Person'], ['first_name' => 'Other', 'company_name' => 'Other Ltd']);
    $user = registeredCustomer();

    $this->actingAs($user, 'web')->put(route('account.profile.update'), profileInput([
        'id' => $other->getKey(),
        'user_id' => $other->getKey(),
        'customer_id' => $other->latestCustomer()->getKey(),
    ]));

    expect($other->fresh()->name)->toBe('Other Person')
        ->and($other->fresh()->latestCustomer()->company_name)->toBe('Other Ltd')
        ->and($user->fresh()->latestCustomer()->company_name)->toBe('Smith Building Ltd');
});

it('creates a Lunar customer for a user who has none', function (): void {
    $user = User::factory()->create(['email' => 'jo@builder.example']);

    $this->actingAs($user, 'web')->put(route('account.profile.update'), profileInput())->assertSessionHasNoErrors();

    $customer = $user->fresh()->latestCustomer();
    expect($customer->company_name)->toBe('Smith Building Ltd')
        ->and($customer->first_name)->toBe('Joanne')
        ->and($customer->customerGroups->pluck('id')->all())->toBe([CustomerGroup::getDefault()->id]);
});

it('saves the details when the search engine is down, and indexes after the commit', function (): void {
    Exceptions::fake();

    $engine = new class extends NullEngine
    {
        /** @var list<int> */
        public array $levels = [];

        public function update($models): void
        {
            $this->levels[] = DB::transactionLevel();

            throw new RuntimeException('Meilisearch is down');
        }
    };
    app(EngineManager::class)->extend('down', fn () => $engine);

    $user = registeredCustomer();
    config(['scout.driver' => 'down']);
    $baseline = DB::transactionLevel();

    $this->actingAs($user, 'web')->put(route('account.profile.update'), profileInput())->assertRedirect(route('account'));

    expect($user->fresh()->latestCustomer()->company_name)->toBe('Smith Building Ltd')
        ->and($engine->levels)->toBe([$baseline]);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Meilisearch is down');
});

it('changes the password with the current one', function (): void {
    $user = registeredCustomer(['remember_token' => 'old-token']);

    $this->actingAs($user, 'web')->get(route('account'));
    $before = session()->getId();

    $this->put(route('account.password.update'), [
        'current_password' => 'the-old-password-1',
        'password' => 'a-brand-new-password-2',
        'password_confirmation' => 'a-brand-new-password-2',
    ])->assertRedirect(route('account'))->assertSessionHas('status', 'Your password has been changed.');

    expect(Hash::check('a-brand-new-password-2', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->remember_token)->not->toBe('old-token')
        ->and(session()->getId())->not->toBe($before);
    $this->assertAuthenticatedAs($user, 'web');
});

it('refuses a password change with the wrong current password or a weak new one', function (array $input, string $field): void {
    $user = registeredCustomer();

    $this->actingAs($user, 'web')->from(route('account'))
        ->put(route('account.password.update'), array_merge([
            'current_password' => 'the-old-password-1',
            'password' => 'a-brand-new-password-2',
            'password_confirmation' => 'a-brand-new-password-2',
        ], $input))
        ->assertRedirect(route('account'))
        ->assertSessionHasErrors($field);

    expect(Hash::check('the-old-password-1', $user->fresh()->password))->toBeTrue();
})->with([
    'wrong current password' => [['current_password' => 'not-the-password-1'], 'current_password'],
    'no current password' => [['current_password' => ''], 'current_password'],
    'too short' => [['password' => 'abc123', 'password_confirmation' => 'abc123'], 'password'],
    'letters only' => [['password' => 'onlyletterspassword', 'password_confirmation' => 'onlyletterspassword'], 'password'],
    'not confirmed' => [['password_confirmation' => 'something-else-123'], 'password'],
    'same as the current password' => [['password' => 'the-old-password-1', 'password_confirmation' => 'the-old-password-1'], 'password'],
]);

it('never writes a password back into the page', function (): void {
    $user = registeredCustomer();

    $html = $this->actingAs($user, 'web')->followingRedirects()->from(route('account'))
        ->put(route('account.password.update'), [
            'current_password' => 'not-the-password-1',
            'password' => 'a-brand-new-password-2',
            'password_confirmation' => 'a-brand-new-password-2',
        ])->getContent();

    expect($html)->not->toContain('not-the-password-1')->not->toContain('a-brand-new-password-2');
});

it('lets the customer log in with the new password after a change', function (): void {
    $user = registeredCustomer();

    $this->actingAs($user, 'web')->put(route('account.password.update'), [
        'current_password' => 'the-old-password-1',
        'password' => 'a-brand-new-password-2',
        'password_confirmation' => 'a-brand-new-password-2',
    ]);
    $this->post(route('logout'));

    $this->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'the-old-password-1'])->assertSessionHasErrors('email');
    $this->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'a-brand-new-password-2'])->assertRedirect(route('account'));
});

it('links My account from the header once signed in', function (): void {
    $html = $this->actingAs(registeredCustomer(), 'web')->get(route('home'))->getContent();

    expect($html)->toContain('data-account-link>My account</a>')->toContain('href="'.route('account').'"');
});
