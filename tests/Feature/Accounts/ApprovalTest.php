<?php

declare(strict_types=1);

use App\Admin\Extensions\CustomerAccessResourceExtension;
use App\Domain\Accounts\SetCustomerAccess;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Notifications\CustomerAccountActivated;
use App\Support\Queues;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\CustomerResource\Pages\EditCustomer;
use Lunar\Admin\Filament\Resources\CustomerResource\Pages\ListCustomers;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Customer;

// New registrations are inactive until staff activate them in the admin panel (docs/accounts.md),
// and the activation emails the login details.

beforeEach(function (): void {
    config()->set('store.noindex', true);
    Notification::fake();
});

/** A registered customer: a login and a Lunar customer, as RegisterCustomer makes them. */
function pendingCustomer(array $attributes = []): array
{
    $user = User::factory()->pending()->unverified()->create(array_merge([
        'name' => 'Jo Builder',
        'email' => 'jo@builder.example',
        'password' => 'a-good-password-1',
    ], $attributes));

    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd']);
    $user->customers()->attach($customer);

    return [$user, $customer];
}

it('refuses a login that staff have not activated, with a message that says so', function (): void {
    [$user] = pendingCustomer();

    $this->from(route('login'))
        ->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'a-good-password-1'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => LoginRequest::PENDING]);

    $this->assertGuest('web');
});

it('gives a wrong password on a pending login the ordinary message, so nothing is revealed', function (): void {
    pendingCustomer();

    $this->from(route('login'))
        ->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'not-the-password-1'])
        ->assertSessionHasErrors(['email' => LoginRequest::FAILED]);
});

it('does not count the pending message as a failed attempt', function (): void {
    pendingCustomer();

    foreach (range(1, 8) as $attempt) {
        $this->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'a-good-password-1'])
            ->assertSessionHasErrors(['email' => LoginRequest::PENDING]);
    }
});

it('lets the customer in once the login is activated', function (): void {
    [$user, $customer] = pendingCustomer();

    app(SetCustomerAccess::class)($customer, true, false);

    $this->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'a-good-password-1'])
        ->assertRedirect(route('shop'));

    $this->assertAuthenticated('web');
});

it('activates every login on the customer, marks the address verified, and emails the details once', function (): void {
    [$user, $customer] = pendingCustomer();
    $second = User::factory()->pending()->unverified()->create(['email' => 'sam@builder.example']);
    $customer->users()->attach($second);

    app(SetCustomerAccess::class)($customer, true, false);

    foreach ([$user, $second] as $login) {
        $login->refresh();
        expect($login->isApproved())->toBeTrue()
            ->and($login->hasVerifiedEmail())->toBeTrue();
        Notification::assertSentToTimes($login, CustomerAccountActivated::class, 1);
    }

    // Saving again changes nothing and sends nothing more.
    app(SetCustomerAccess::class)($customer, true, true);
    Notification::assertSentToTimes($user, CustomerAccountActivated::class, 1);
});

it('puts the login URL and username in the activation email, and never a password', function (): void {
    [$user] = pendingCustomer();

    $html = (string) (new CustomerAccountActivated)->toMail($user)->render();

    expect($html)
        ->toContain(route('login'))
        ->toContain('jo@builder.example')
        ->toContain('the password you chose when you registered')
        ->not->toContain('a-good-password-1');
});

it('queues the registration and activation emails on the default queue', function (): void {
    expect((new CustomerAccountActivated)->queue)->toBe(Queues::DEFAULT)
        ->and((new \App\Notifications\CustomerRegistrationReceived)->queue)->toBe(Queues::DEFAULT);
});

it('keeps the activation when the email cannot be sent, and reports the failure', function (): void {
    Exceptions::fake();
    [$user, $customer] = pendingCustomer();

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

    app(SetCustomerAccess::class)($customer, true, false);

    expect($user->refresh()->isApproved())->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Mailer is down');
});

it('switches 30 day terms on and off for the customer without touching approval', function (): void {
    [$user, $customer] = pendingCustomer();
    $user->forceFill(['approved_at' => now()])->save();

    app(SetCustomerAccess::class)($customer, true, true);
    expect($user->refresh()->hasAccountTerms())->toBeTrue();

    app(SetCustomerAccess::class)($customer, true, false);
    expect($user->refresh()->hasAccountTerms())->toBeFalse()->and($user->isApproved())->toBeTrue();
});

it('switches a login off, and signs the customer out on their next request', function (): void {
    [$user, $customer] = pendingCustomer();
    app(SetCustomerAccess::class)($customer, true, false);

    $this->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'a-good-password-1']);
    $this->assertAuthenticated('web');

    app(SetCustomerAccess::class)($customer, false, false);

    // The test app keeps the guard's user between requests; a real request loads it afresh.
    auth('web')->setUser($user->fresh());

    $this->get(route('shop'))->assertRedirect(route('login'));
    $this->assertGuest('web');
});

it('does not end a staff session when it signs a customer out', function (): void {
    [$user, $customer] = pendingCustomer();
    app(SetCustomerAccess::class)($customer, true, false);

    $this->actingAs($user->refresh(), 'web')->actingAs(Staff::factory()->create(), 'staff');
    app(SetCustomerAccess::class)($customer, false, false);
    auth('web')->setUser($user->fresh());

    $this->get(route('shop'));

    $this->assertGuest('web');
    $this->assertAuthenticated('staff');
});

it('lets a pending customer ask for a password reset, but not sign in', function (): void {
    pendingCustomer();

    $this->post(route('password.email'), ['email' => 'jo@builder.example'])->assertSessionHasNoErrors();

    $this->post(route('login'), ['email' => 'jo@builder.example', 'password' => 'a-good-password-1'])
        ->assertSessionHasErrors(['email' => LoginRequest::PENDING]);
});

// --- The admin panel ---------------------------------------------------------------------

it('activates a customer and turns on 30 day terms from the Lunar Edit Customer page', function (): void {
    [$user, $customer] = pendingCustomer();

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertFormSet([
            CustomerAccessResourceExtension::LOGIN_FIELD => false,
            CustomerAccessResourceExtension::TERMS_FIELD => false,
        ])
        ->fillForm([
            CustomerAccessResourceExtension::LOGIN_FIELD => true,
            CustomerAccessResourceExtension::TERMS_FIELD => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->isApproved())->toBeTrue()
        ->and($user->hasAccountTerms())->toBeTrue();
    Notification::assertSentToTimes($user, CustomerAccountActivated::class, 1);
});

it('approves a new registration with the Approve button on the customers list, and emails them', function (): void {
    [$user, $customer] = pendingCustomer();

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(ListCustomers::class)
        ->assertTableActionVisible('approve', $customer)
        ->callTableAction('approve', $customer)
        ->assertHasNoTableActionErrors();

    expect($user->refresh()->isApproved())->toBeTrue()
        ->and($user->hasAccountTerms())->toBeFalse();
    Notification::assertSentToTimes($user, CustomerAccountActivated::class, 1);

    // Nothing left to approve, so the button goes.
    Livewire::test(ListCustomers::class)->assertTableActionHidden('approve', $customer);
});

it('approves from the Edit Customer page and keeps 30 day terms as they were', function (): void {
    [$user, $customer] = pendingCustomer();
    $user->forceFill(['account_terms' => true])->save();

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertActionVisible('approve')
        ->callAction('approve');

    expect($user->refresh()->isApproved())->toBeTrue()
        ->and($user->hasAccountTerms())->toBeTrue();
    Notification::assertSentToTimes($user, CustomerAccountActivated::class, 1);
});

it('approves every selected customer that is waiting with the bulk action', function (): void {
    [$first, $firstCustomer] = pendingCustomer();
    [$second, $secondCustomer] = pendingCustomer(['email' => 'sam@builder.example']);

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(ListCustomers::class)
        ->callTableBulkAction('approve', [$firstCustomer, $secondCustomer]);

    expect($first->refresh()->isApproved())->toBeTrue()
        ->and($second->refresh()->isApproved())->toBeTrue();
});
