<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');

        if (! Schema::hasTable($tables['permissions']) || ! Schema::hasTable($tables['roles'])) {
            return;
        }

        // The admin's staff guard (Lunar's own: `staff`). A Reports role with one permission, View reports: it opens Invoices
        // and Financial reporting, and the dashboard every staff member has. Nothing that Lunar owns is changed.
        $guard = 'staff';

        $permission = Permission::query()->firstOrCreate(['name' => 'reports:view', 'guard_name' => $guard]);
        $role = Role::query()->firstOrCreate(['name' => 'reports', 'guard_name' => $guard]);

        $role->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tables = config('permission.table_names');

        if (! Schema::hasTable($tables['permissions']) || ! Schema::hasTable($tables['roles'])) {
            return;
        }

        Role::query()->where(['name' => 'reports', 'guard_name' => 'staff'])->delete();
        Permission::query()->where(['name' => 'reports:view', 'guard_name' => 'staff'])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
