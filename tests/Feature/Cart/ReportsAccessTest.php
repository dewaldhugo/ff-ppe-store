<?php

declare(strict_types=1);

use App\Admin\Pages\FinancialReporting;
use App\Admin\ReportsAccess;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Widgets\RecentOrdersWidget;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\CustomerResource;
use Lunar\Admin\Filament\Resources\OrderResource;
use Lunar\Admin\Filament\Resources\ProductResource;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Channel;
use Lunar\Models\Order;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// The Reports role: a login that sees the dashboard, Invoices and Financial reporting only
// (App\Admin\ReportsAccess, the migration create_reports_role_and_permission, php artisan staff:create-reports-user).

function reportsStaff(string ...$roles): Staff
{
    $staff = Staff::factory()->create(['admin' => false]);

    foreach ($roles as $role) {
        $staff->assignRole($role);
    }

    test()->actingAs($staff, 'staff');
    Filament::setCurrentPanel(Filament::getPanel('lunar'));

    return $staff;
}

function reportsOrder(): Order
{
    return Order::withoutEvents(fn (): Order => Order::factory()->create([
        'channel_id' => Channel::getDefault()->id,
        'status' => 'payment-received',
        'placed_at' => '2026-03-10 09:00:00',
    ]));
}

// --- The role and the permission -------------------------------------------------------------

it('has a Reports role on the staff guard that holds View reports and nothing else', function (): void {
    $role = Role::query()->where(['name' => 'reports', 'guard_name' => 'staff'])->firstOrFail();

    expect($role->permissions->pluck('name')->all())->toBe(['reports:view'])
        ->and(Permission::query()->where(['name' => 'reports:view', 'guard_name' => 'staff'])->exists())->toBeTrue();
});

it('names the role and the permission in the Staff screens', function (): void {
    expect(__('lunarpanel::auth.roles.reports.label'))->toBe('Reports')
        ->and(__('lunarpanel::auth.permissions.reports:view.label'))->toBe('View reports')
        // Lunar's own labels are still there beside it.
        ->and(__('lunarpanel::auth.permissions.sales:manage-orders.label'))->toBe('Manage Orders');
});

// --- What the role sees ----------------------------------------------------------------------

it('lets a Reports user open Invoices and Financial reporting', function (): void {
    reportsStaff('reports');

    expect(ReportsAccess::allowed())->toBeTrue()
        ->and(InvoiceResource::canViewAny())->toBeTrue()
        ->and(FinancialReporting::canAccess())->toBeTrue();
});

it('keeps a Reports user out of orders, customers and products', function (): void {
    reportsStaff('reports');

    expect(ReportsAccess::canOpenOrders())->toBeFalse()
        ->and(OrderResource::can('viewAny'))->toBeFalse()
        ->and(CustomerResource::can('viewAny'))->toBeFalse()
        ->and(ProductResource::can('viewAny'))->toBeFalse();
});

it('shows a Reports user Invoices and Financial reporting in the menu', function (): void {
    reportsStaff('reports');

    expect(InvoiceResource::shouldRegisterNavigation())->toBeTrue()
        ->and(FinancialReporting::shouldRegisterNavigation())->toBeTrue()
        ->and(OrderResource::can('viewAny'))->toBeFalse();
});

it('keeps a member of staff with no role out of Invoices and Financial reporting', function (): void {
    reportsStaff();

    expect(ReportsAccess::allowed())->toBeFalse()
        ->and(InvoiceResource::canViewAny())->toBeFalse()
        ->and(FinancialReporting::canAccess())->toBeFalse();
});

it('still lets staff who manage orders open Invoices, Financial reporting and the orders', function (): void {
    $staff = reportsStaff();
    $staff->givePermissionTo('sales:manage-orders');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(ReportsAccess::allowed())->toBeTrue()
        ->and(ReportsAccess::canOpenOrders())->toBeTrue()
        ->and(InvoiceResource::canViewAny())->toBeTrue()
        ->and(FinancialReporting::canAccess())->toBeTrue();
});

it('lets an admin through everywhere', function (): void {
    $staff = Staff::factory()->create(['admin' => true]);
    test()->actingAs($staff, 'staff');
    Filament::setCurrentPanel(Filament::getPanel('lunar'));

    expect(ReportsAccess::allowed())->toBeTrue()
        ->and(ReportsAccess::canOpenOrders())->toBeTrue();
});

it('gives a Reports user the Invoices and Latest orders rows without a link to the order', function (): void {
    $order = reportsOrder();

    reportsStaff('reports');

    $table = Livewire::test(RecentOrdersWidget::class)->instance()->getTable();

    expect($table->getRecordUrl($order))->toBeNull();
});

it('links a row of Latest orders to its order for staff who manage orders', function (): void {
    $order = reportsOrder();

    $staff = reportsStaff();
    $staff->givePermissionTo('sales:manage-orders');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $table = Livewire::test(RecentOrdersWidget::class)->instance()->getTable();

    expect($table->getRecordUrl($order))->toBe(OrderResource::getUrl('order', ['record' => $order]));
});

// --- Making the user -------------------------------------------------------------------------

it('creates a Reports user from the command, asking for the password and never showing it', function (): void {
    $this->artisan('staff:create-reports-user', ['email' => 'accounts@example.com', '--first-name' => 'Ann', '--last-name' => 'Counts'])
        ->expectsQuestion('Password (at least 10 characters, with letters and numbers)', 'Sturdy-Pass-2026')
        ->expectsQuestion('Password again', 'Sturdy-Pass-2026')
        ->expectsOutputToContain('Created Ann Counts (accounts@example.com)')
        ->doesntExpectOutputToContain('Sturdy-Pass-2026')
        ->assertSuccessful();

    $staff = Staff::query()->where('email', 'accounts@example.com')->sole();

    expect($staff->admin)->toBeFalse()
        ->and($staff->hasRole('reports'))->toBeTrue()
        ->and($staff->getRoleNames()->all())->toBe(['reports'])
        ->and(Hash::check('Sturdy-Pass-2026', $staff->password))->toBeTrue()
        ->and($staff->password)->not->toBe('Sturdy-Pass-2026');
});

it('asks for the names when they are not given', function (): void {
    $this->artisan('staff:create-reports-user', ['email' => 'asked@example.com'])
        ->expectsQuestion('First name', 'Ann')
        ->expectsQuestion('Last name', 'Counts')
        ->expectsQuestion('Password (at least 10 characters, with letters and numbers)', 'Sturdy-Pass-2026')
        ->expectsQuestion('Password again', 'Sturdy-Pass-2026')
        ->assertSuccessful();

    expect(Staff::query()->where('email', 'asked@example.com')->exists())->toBeTrue();
});

it('creates nothing for a weak password, mismatched passwords, a bad or taken email', function (): void {
    $this->artisan('staff:create-reports-user', ['email' => 'weak@example.com', '--first-name' => 'A', '--last-name' => 'B'])
        ->expectsQuestion('Password (at least 10 characters, with letters and numbers)', 'short1')
        ->expectsOutputToContain('too weak')
        ->assertFailed();

    $this->artisan('staff:create-reports-user', ['email' => 'mismatch@example.com', '--first-name' => 'A', '--last-name' => 'B'])
        ->expectsQuestion('Password (at least 10 characters, with letters and numbers)', 'Sturdy-Pass-2026')
        ->expectsQuestion('Password again', 'Different-Pass-2026')
        ->expectsOutputToContain('not the same')
        ->assertFailed();

    $this->artisan('staff:create-reports-user', ['email' => 'not an email'])->expectsOutputToContain('is not an email address')->assertFailed();

    Staff::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('staff:create-reports-user', ['email' => 'taken@example.com', '--first-name' => 'A', '--last-name' => 'B'])
        ->expectsOutputToContain('already a staff member')
        ->assertFailed();

    expect(Staff::query()->whereIn('email', ['weak@example.com', 'mismatch@example.com', 'not an email'])->count())->toBe(0);
});
