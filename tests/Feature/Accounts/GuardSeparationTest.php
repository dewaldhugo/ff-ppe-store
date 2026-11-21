<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Support\Facades\Auth;
use Lunar\Admin\Models\Staff;

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('keeps a signed-in customer out of the Lunar admin panel', function (string $path): void {
    $this->actingAs(User::factory()->create(), 'web')
        ->get($path)
        ->assertRedirectContains('/admin/login');
})->with(['/admin', '/admin/products', '/admin/customers', '/admin/staff']);

it('serves the admin panel at /admin and nothing at the old /lunar address', function (): void {
    expect(Filament::getPanel('lunar')->getPath())->toBe('admin');

    $this->get('/admin/login')->assertOk();
    $this->get('/lunar')->assertNotFound();
    $this->get('/lunar/login')->assertNotFound();
});

it('keeps a customer out of the admin panel even when their email matches a staff member', function (): void {
    Staff::factory()->create(['email' => 'shared@example.test']);
    $customer = User::factory()->create(['email' => 'shared@example.test']);

    $this->actingAs($customer, 'web')->get('/admin')->assertRedirectContains('/admin/login');

    expect(Auth::guard('staff')->check())->toBeFalse();
});

it('keeps a customer out of Horizon', function (): void {
    $this->actingAs(User::factory()->create(), 'web')->get('/horizon')->assertForbidden();
});

it('lets staff into the admin panel on the staff guard', function (): void {
    $this->actingAs(Staff::factory()->create(), 'staff')->get('/admin')->assertOk();
});

it('uses separate guards, providers and models for customers and staff', function (): void {
    expect(config('auth.guards.web.provider'))->toBe('users')
        ->and(config('auth.guards.staff.provider'))->toBe('staff')
        ->and(Auth::guard('web')->getProvider()->getModel())->toBe(User::class)
        ->and(Auth::guard('staff')->getProvider()->getModel())->toBe(Staff::class)
        ->and(Auth::guard('web')->getName())->not->toBe(Auth::guard('staff')->getName())
        ->and(new User)->not->toBeInstanceOf(Staff::class)
        ->and(new User)->not->toBeInstanceOf(FilamentUser::class);
});

it('does not treat a staff member as a customer', function (): void {
    $this->actingAs(Staff::factory()->create(), 'staff');

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and($html)->toContain('data-login-link')
        ->and($html)->toContain('data-register-link')
        ->and($html)->not->toContain('data-account-link')
        ->and($html)->not->toContain('data-logout-button');
});

it('does not let staff credentials log in to the storefront', function (): void {
    Staff::factory()->create(['email' => 'staff@example.test', 'password' => bcrypt('a-staff-password-1')]);

    $this->post(route('login'), ['email' => 'staff@example.test', 'password' => 'a-staff-password-1'])
        ->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

it('does not let a customer log in as staff by sharing a password', function (): void {
    User::factory()->create(['email' => 'jo@builder.example', 'password' => 'a-good-password-1']);

    expect(Auth::guard('staff')->attempt(['email' => 'jo@builder.example', 'password' => 'a-good-password-1']))->toBeFalse();
});

it('does not end a staff session when a customer logs out', function (): void {
    $customer = User::factory()->create();
    $staff = Staff::factory()->create();
    $webKey = Auth::guard('web')->getName();
    $staffKey = Auth::guard('staff')->getName();

    $this->withSession([$webKey => $customer->getKey(), $staffKey => $staff->getKey()])
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    expect(session()->has($webKey))->toBeFalse()
        ->and(session()->get($staffKey))->toBe($staff->getKey());
});
