<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Admin\ReportsAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Lunar\Admin\Models\Staff;
use Spatie\Permission\Models\Role;

/**
 * Makes an admin login that sees the dashboard, Invoices and Financial reporting and nothing else: a staff member (not an
 * admin) with the Reports role (App\Admin\ReportsAccess). The password is asked for here, twice, and is never shown or kept
 * anywhere but as a hash in the staff table. The same user can be made in the admin (Settings, Staff) by giving them the
 * Reports role and no other.
 */
final class CreateReportsStaff extends Command
{
    protected $signature = 'staff:create-reports-user
        {email : The login email address}
        {--first-name= : First name (asked for when left out)}
        {--last-name= : Last name (asked for when left out)}';

    protected $description = 'Create an admin user who sees only the dashboard, Invoices and Financial reporting';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']])->fails()) {
            $this->error("'{$email}' is not an email address.");

            return self::FAILURE;
        }

        if (Staff::withTrashed()->where('email', $email)->exists()) {
            $this->error("There is already a staff member with the email {$email}. Nothing was created.");

            return self::FAILURE;
        }

        if (! Role::query()->where(['name' => ReportsAccess::ROLE, 'guard_name' => 'staff'])->exists()) {
            $this->error('The Reports role does not exist yet. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $first = trim((string) ($this->option('first-name') ?: $this->ask('First name')));
        $last = trim((string) ($this->option('last-name') ?: $this->ask('Last name')));

        if ($first === '' || $last === '') {
            $this->error('A first and a last name are needed.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 10 characters, with letters and numbers)');

        if (Validator::make(['password' => $password], ['password' => ['required', Password::min(10)->letters()->numbers()]])->fails()) {
            $this->error('That password is too weak: use at least 10 characters with letters and numbers. Nothing was created.');

            return self::FAILURE;
        }

        if ($password !== (string) $this->secret('Password again')) {
            $this->error('The two passwords are not the same. Nothing was created.');

            return self::FAILURE;
        }

        $staff = Staff::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'password' => $password,
            'admin' => false,
        ]);

        $staff->assignRole(ReportsAccess::ROLE);

        $this->info("Created {$first} {$last} ({$email}). They sign in at /admin and see the dashboard, Invoices and Financial reporting only.");

        return self::SUCCESS;
    }
}
