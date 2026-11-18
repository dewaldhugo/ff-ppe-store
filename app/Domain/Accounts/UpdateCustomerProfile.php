<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Customer;
use Lunar\Models\CustomerGroup;
use Throwable;

/**
 * Saves a customer's contact and company details on the User and on their Lunar Customer,
 * in one transaction. A changed email address is no longer verified: the customer is sent a
 * new verification link (when verification is switched on).
 */
final class UpdateCustomerProfile
{
    /**
     * @param  array{first_name: string, last_name: string, company_name: string, vat_number?: ?string, phone?: ?string, email: string}  $data
     * @return bool Whether the email address changed.
     */
    public function __invoke(User $user, array $data): bool
    {
        $emailChanged = mb_strtolower($user->email) !== mb_strtolower($data['email']);
        $customer = null;

        DB::transaction(function () use ($user, $data, $emailChanged, &$customer): void {
            $user->forceFill([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            if ($emailChanged) {
                $user->email_verified_at = null;
            }

            $user->save();

            $customer = CustomerSearch::withoutIndexing(function () use ($user, $data): Customer {
                $customer = $user->latestCustomer() ?? $this->createCustomer($user);

                $customer->forceFill([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'company_name' => $data['company_name'],
                    'tax_identifier' => $data['vat_number'] ?? null,
                ])->save();

                return $customer;
            });
        });

        CustomerSearch::index($customer);

        if ($emailChanged) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $emailChanged;
    }

    /**
     * A user created outside registration (for example by a seeder or a developer) may have
     * no customer yet. Give them one so their company details have somewhere to live.
     */
    private function createCustomer(User $user): Customer
    {
        $customer = Customer::create(['first_name' => $user->name, 'last_name' => '-']);

        $user->customers()->attach($customer);

        if ($group = CustomerGroup::getDefault()) {
            $customer->customerGroups()->attach($group);
        }

        return $customer;
    }
}
