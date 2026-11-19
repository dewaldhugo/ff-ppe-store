<?php

declare(strict_types=1);

use App\Domain\Accounts\RegisterCustomer;
use App\Domain\Accounts\VatNumber;
use App\Models\User;
use App\Notifications\CustomerRegistrationReceived;
use App\Notifications\CustomerVerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Lunar\Models\Customer;
use Lunar\Models\CustomerGroup;

beforeEach(function (): void {
    config()->set('store.noindex', true);
    config()->set('store.require_email_verification', true);
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registration(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Jo',
        'last_name' => 'Builder',
        'company_name' => 'Builder & Sons Ltd',
        'address_line_one' => '1 High Street',
        'address_line_two' => 'Unit 4',
        'address_city' => 'Croydon',
        'address_county' => 'Surrey',
        'address_postcode' => 'cr0 1xx',
        'industry' => 'construction',
        'vat_number' => 'GB 123 4567 82',
        'phone' => '020 8289 5858',
        'email' => 'jo@builder.example',
        'password' => 'a-good-password-1',
        'password_confirmation' => 'a-good-password-1',
    ], $overrides);
}

it('shows the register form with every field, the honeypot and the terms line', function (): void {
    $html = $this->get(route('register'))->assertOk()->getContent();

    expect($html)
        ->toContain('name="first_name"')
        ->toContain('name="last_name"')
        ->toContain('name="company_name"')
        ->toContain('name="address_line_one"')
        ->toContain('name="address_line_two"')
        ->toContain('name="address_city"')
        ->toContain('name="address_county"')
        ->toContain('name="address_postcode"')
        ->toContain('<select')
        ->toContain('name="industry"')
        ->toContain('<option value="construction"')
        ->toContain('name="vat_number"')
        ->toContain('name="phone"')
        ->toContain('name="email"')
        ->toContain('name="password"')
        ->toContain('name="password_confirmation"')
        ->toContain('name="website"')
        ->toContain('<meta name="robots" content="noindex">')
        ->toContain('By registering you agree to our')
        ->toContain('href="'.route('terms').'"')
        ->toContain('href="'.route('privacy').'"')
        ->not->toContain('type="checkbox"');
});

it('creates a user and a Lunar customer attached to it, with the default customer group', function (): void {
    $this->post(route('register'), registration())->assertRedirect(route('home'));

    $user = User::query()->where('email', 'jo@builder.example')->firstOrFail();
    $customer = $user->customers()->firstOrFail();

    expect($user->name)->toBe('Jo Builder')
        ->and($user->phone)->toBe('020 8289 5858')
        ->and($user->password)->not->toBe('a-good-password-1')
        ->and($user->email_verified_at)->toBeNull()
        ->and($customer->first_name)->toBe('Jo')
        ->and($customer->last_name)->toBe('Builder')
        ->and($customer->company_name)->toBe('Builder & Sons Ltd')
        ->and($customer->tax_identifier)->toBe('GB123456782')
        ->and($user->latestCustomer()?->is($customer))->toBeTrue()
        ->and($customer->customerGroups->pluck('id')->all())->toBe([CustomerGroup::getDefault()->id]);
});

it('saves the company address as the billing address, and the industry on the customer', function (): void {
    $this->post(route('register'), registration())->assertSessionHasNoErrors();

    $customer = User::query()->where('email', 'jo@builder.example')->firstOrFail()->customers()->firstOrFail();
    $address = $customer->addresses()->sole();

    expect($customer->meta['industry'])->toBe('construction')
        ->and($address->company_name)->toBe('Builder & Sons Ltd')
        ->and($address->line_one)->toBe('1 High Street')
        ->and($address->line_two)->toBe('Unit 4')
        ->and($address->city)->toBe('Croydon')
        ->and($address->state)->toBe('Surrey')
        // Upper case, with the space before the last three characters.
        ->and($address->postcode)->toBe('CR0 1XX')
        ->and($address->country->iso2)->toBe('GB')
        ->and($address->billing_default)->toBeTrue()
        ->and($address->contact_email)->toBe('jo@builder.example');
});

it('accepts the postcode however it is typed, and leaves the optional address lines empty', function (string $typed, string $stored): void {
    $this->post(route('register'), registration([
        'address_postcode' => $typed, 'address_line_two' => '', 'address_county' => '',
    ]))->assertSessionHasNoErrors();

    $address = User::query()->where('email', 'jo@builder.example')->firstOrFail()->customers()->firstOrFail()->addresses()->sole();

    expect($address->postcode)->toBe($stored)
        ->and($address->line_two)->toBeNull()
        ->and($address->state)->toBeNull();
})->with([
    'lower case' => ['sw1a1aa', 'SW1A 1AA'],
    'extra spaces' => ['  m1   1ae ', 'M1 1AE'],
    'already tidy' => ['EC1A 1BB', 'EC1A 1BB'],
]);

it('does not log the new customer in, and starts them unapproved with card payment only', function (): void {
    $this->get(route('register'));
    $before = session()->getId();

    $this->post(route('register'), registration())
        ->assertRedirect(route('home'))
        ->assertSessionHas('status', 'Your registration was successful. We will contact you shortly.');

    // The home page shows it once, in the storefront's notice banner.
    $this->get(route('home'))->assertOk()
        ->assertSee('data-flash', false)
        ->assertSee('Your registration was successful. We will contact you shortly.');
    $this->get(route('home'))->assertDontSee('data-flash', false);

    $this->assertGuest('web');
    $this->assertGuest('staff');
    expect(session()->getId())->not->toBe($before);

    $user = User::query()->where('email', 'jo@builder.example')->firstOrFail();
    expect($user->isApproved())->toBeFalse()
        ->and($user->approved_at)->toBeNull()
        ->and($user->hasAccountTerms())->toBeFalse()
        ->and($user->email_verified_at)->toBeNull();
});

it('registers with only the required fields', function (): void {
    $this->post(route('register'), registration(['vat_number' => '', 'phone' => '']))->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'jo@builder.example')->firstOrFail();

    expect($user->phone)->toBeNull()
        ->and($user->customers()->first()->tax_identifier)->toBeNull();
});

it('lower-cases and trims the email address', function (): void {
    $this->post(route('register'), registration(['email' => '  Jo@Builder.EXAMPLE ']))->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'jo@builder.example')->exists())->toBeTrue();
});

it('rejects invalid registrations and creates nothing', function (array $overrides, string $field): void {
    $this->from(route('register'))->post(route('register'), registration($overrides))
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors($field);

    expect(User::count())->toBe(0)
        ->and(Customer::query()->where('company_name', 'Builder & Sons Ltd')->exists())->toBeFalse();
    $this->assertGuest('web');
})->with([
    'no first name' => [['first_name' => ''], 'first_name'],
    'no last name' => [['last_name' => ''], 'last_name'],
    'no company' => [['company_name' => ''], 'company_name'],
    'no address line 1' => [['address_line_one' => ''], 'address_line_one'],
    'no town or city' => [['address_city' => ''], 'address_city'],
    'no postcode' => [['address_postcode' => ''], 'address_postcode'],
    'bad postcode' => [['address_postcode' => 'NOT A POSTCODE'], 'address_postcode'],
    'no industry' => [['industry' => ''], 'industry'],
    'unknown industry' => [['industry' => 'pirate-radio'], 'industry'],
    'no email' => [['email' => ''], 'email'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'no password' => [['password' => '', 'password_confirmation' => ''], 'password'],
    'short password' => [['password' => 'abc123', 'password_confirmation' => 'abc123'], 'password'],
    'letters only password' => [['password' => 'onlyletterspassword', 'password_confirmation' => 'onlyletterspassword'], 'password'],
    'password not confirmed' => [['password_confirmation' => 'something-else-123'], 'password'],
    'bad VAT number' => [['vat_number' => '12345'], 'vat_number'],
    'bad phone' => [['phone' => 'call me maybe'], 'phone'],
    'name too long' => [['first_name' => str_repeat('a', 101)], 'first_name'],
]);

it('refuses an email address that is already registered', function (): void {
    User::factory()->create(['email' => 'jo@builder.example']);

    $this->from(route('register'))->post(route('register'), registration())
        ->assertSessionHasErrors(['email' => 'An account with this email address already exists. Log in instead.']);

    expect(User::count())->toBe(1);
});

it('accepts valid UK VAT numbers and rejects invalid ones', function (string $value, bool $valid): void {
    expect(VatNumber::isValid((string) VatNumber::normalise($value)))->toBe($valid);
})->with([
    ['GB123456789', true],
    ['123456789', true],
    ['gb 123 4567 89', true],
    ['GB-123-4567-89', true],
    ['GB123456789012', true],
    ['XI123456789', true],
    ['GBGD123', true],
    ['GBHA599', true],
    ['GB12345678', false],
    ['GB1234567890', false],
    ['DE123456789', false],
    ['GB12345678A', false],
    ['VAT', false],
]);

it('normalises a blank VAT number to null', function (): void {
    expect(VatNumber::normalise('  '))->toBeNull()
        ->and(VatNumber::normalise(null))->toBeNull()
        ->and(VatNumber::normalise(' gb 123 4567 89 '))->toBe('GB123456789');
});

it('creates nothing when the honeypot is filled', function (): void {
    $this->post(route('register'), registration(['website' => 'https://spam.example']))->assertRedirect(route('home'));

    expect(User::count())->toBe(0)
        ->and(Customer::query()->where('company_name', 'Builder & Sons Ltd')->exists())->toBeFalse();
    $this->assertGuest('web');
    Notification::assertNothingSent();
});

it('allows 10 registration attempts an hour per IP, then answers 429', function (): void {
    foreach (range(1, 10) as $attempt) {
        $this->post(route('register'), [])->assertRedirect();
    }

    $this->post(route('register'), registration())->assertStatus(429);

    expect(User::count())->toBe(0);
});

it('rolls back the user when the customer cannot be created', function (): void {
    $before = User::count();

    expect(fn () => app(RegisterCustomer::class)(registration([
        'company_name' => str_repeat('x', 400),
        'vat_number' => null,
    ])))->toThrow(QueryException::class);

    expect(User::count())->toBe($before)
        ->and(User::query()->where('email', 'jo@builder.example')->exists())->toBeFalse();
});

it('still registers the customer when the search engine is down, and indexes after the commit', function (): void {
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
    config(['scout.driver' => 'down']);

    $baseline = DB::transactionLevel();

    $this->post(route('register'), registration())->assertRedirect(route('home'));

    expect(User::query()->where('email', 'jo@builder.example')->exists())->toBeTrue()
        ->and($engine->levels)->toBe([$baseline]);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Meilisearch is down');
});

it('completes registration and reports the problem when no default customer group exists', function (): void {
    Exceptions::fake();
    CustomerGroup::query()->update(['default' => false]);

    $this->post(route('register'), registration())->assertRedirect(route('home'));

    $customer = User::query()->where('email', 'jo@builder.example')->firstOrFail()->customers()->firstOrFail();

    expect($customer->customerGroups)->toHaveCount(0);
    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'No default customer group exists'));
});

it('emails the new customer that they will be told when their login is activated', function (): void {
    $this->post(route('register'), registration());

    $user = User::query()->where('email', 'jo@builder.example')->firstOrFail();
    Notification::assertSentToTimes($user, CustomerRegistrationReceived::class, 1);

    $html = (string) (new CustomerRegistrationReceived)->toMail($user)->render();
    expect($html)->toContain('as soon as your login has been activated');
});

it('sends no verification email at registration, whatever the verification switch says', function (bool $required): void {
    config()->set('store.require_email_verification', $required);

    $this->post(route('register'), registration())->assertRedirect(route('home'));

    Notification::assertNotSentTo(User::query()->firstOrFail(), CustomerVerifyEmail::class);
})->with([true, false]);

it('does not fail the registration when the email cannot be queued', function (): void {
    Exceptions::fake();
    app()->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('Mailer is down');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('Mailer is down');
        }
    });

    $this->post(route('register'), registration())->assertRedirect(route('home'));

    expect(User::query()->where('email', 'jo@builder.example')->exists())->toBeTrue();
    $this->assertGuest('web');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Mailer is down');
});

it('sends a signed-in customer away from the register page', function (): void {
    $this->actingAs(User::factory()->create(), 'web')->get(route('register'))->assertRedirect(route('account'));
});

it('keeps the header register link pointing at the real page', function (): void {
    $html = $this->get(route('home'))->getContent();

    expect($html)->toMatch('#<a href="'.preg_quote(route('register'), '#').'"[^>]*data-register-link>Register</a>#');
});
