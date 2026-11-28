<?php

declare(strict_types=1);

use App\Admin\Pages\FinancialReporting;
use App\Admin\ReportsAccess;
use App\Admin\Resources\InvoiceResource;
use Filament\Facades\Filament;
use Lunar\Admin\Filament\Resources\CustomerResource;
use Lunar\Admin\Filament\Resources\OrderResource;
use Lunar\Admin\Filament\Resources\ProductResource;
use Lunar\Admin\Models\Staff;

// App\Admin\Middleware\EnforceAdminAccess: Lunar only hides a resource from the menu, so the pages themselves are held back here.

/** A member of staff, signed in to the panel, with these roles and permissions (none, for a bare account). */
function accessStaff(bool $admin = false, array $roles = [], array $permissions = []): Staff
{
    $staff = Staff::factory()->create(['admin' => $admin]);

    foreach ($roles as $role) {
        $staff->assignRole($role);
    }

    foreach ($permissions as $permission) {
        $staff->givePermissionTo($permission);
    }

    test()->actingAs($staff, 'staff');
    Filament::setCurrentPanel(Filament::getPanel('lunar'));

    return $staff;
}

/** The status each address of the panel answers with. */
function accessStatuses(array $urls): array
{
    return array_map(fn (string $url): int => test()->get($url)->getStatusCode(), $urls);
}

function adminAddresses(): array
{
    return [
        'dashboard' => Filament::getPanel('lunar')->getUrl(),
        'invoices' => InvoiceResource::getUrl('index'),
        'financial' => FinancialReporting::getUrl(),
        'orders' => OrderResource::getUrl('index'),
        'customers' => CustomerResource::getUrl('index'),
        'products' => ProductResource::getUrl('index'),
        'staff' => route('filament.lunar.resources.staff.index'),
        'taxes' => route('filament.lunar.taxes'),
    ];
}

it('lets an admin open every page', function (): void {
    accessStaff(admin: true);

    // (The Taxes address sends on to the first of its pages, so a redirect is an open door too.)
    expect(accessStatuses(adminAddresses()))->each->toBeIn([200, 302]);
});

it('keeps a Reports user to the dashboard, Invoices and Financial reporting, whatever address they type', function (): void {
    accessStaff(roles: ['reports']);

    expect(ReportsAccess::isReportsOnly(auth('staff')->user()))->toBeTrue()
        ->and(accessStatuses(adminAddresses()))->toBe([
            'dashboard' => 200,
            'invoices' => 200,
            'financial' => 200,
            'orders' => 403,
            'customers' => 403,
            'products' => 403,
            'staff' => 403,
            'taxes' => 403,
        ]);
});

it('keeps a Reports user out of the other pages of a resource too, not only its list', function (): void {
    accessStaff(roles: ['reports']);

    expect(test()->get(route('filament.lunar.resources.customers.create'))->getStatusCode())->toBe(403)
        ->and(test()->get(route('filament.lunar.resources.products.edit', ['record' => 1]))->getStatusCode())->toBe(403)
        ->and(test()->get(route('filament.lunar.taxes.resources.tax-zones.index'))->getStatusCode())->toBe(403);
});

it('lets a Reports user keep their own profile and sign out', function (): void {
    accessStaff(roles: ['reports']);

    expect(test()->get(route('filament.lunar.auth.profile'))->getStatusCode())->toBe(200);
});

it('gives a member of staff who manages orders the orders and the figures, and not the customers or products', function (): void {
    accessStaff(permissions: ['sales:manage-orders']);

    expect(ReportsAccess::isReportsOnly(auth('staff')->user()))->toBeFalse()
        ->and(accessStatuses(adminAddresses()))->toMatchArray([
            'dashboard' => 200,
            'invoices' => 200,
            'financial' => 200,
            'orders' => 200,
            'customers' => 403,
            'products' => 403,
        ]);
});

it('keeps a member of staff with no permissions out of every resource that asks for one, and out of the figures', function (): void {
    accessStaff();

    expect(accessStatuses(adminAddresses()))->toMatchArray([
        'dashboard' => 200,
        'invoices' => 403,
        'financial' => 403,
        'orders' => 403,
        'customers' => 403,
        'products' => 403,
        'staff' => 403,
    ]);
});

it('does not take a Reports user for someone who is only there for the figures when they hold another permission', function (): void {
    accessStaff(roles: ['reports'], permissions: ['catalog:manage-products']);

    expect(ReportsAccess::isReportsOnly(auth('staff')->user()))->toBeFalse()
        ->and(test()->get(ProductResource::getUrl('index'))->getStatusCode())->toBe(200)
        // Still no orders: that permission was never given.
        ->and(test()->get(OrderResource::getUrl('index'))->getStatusCode())->toBe(403);
});

it('sends someone who is not signed in to the sign-in page, not to a 403', function (): void {
    test()->get(OrderResource::getUrl('index'))->assertRedirect(route('filament.lunar.auth.login'));
});
