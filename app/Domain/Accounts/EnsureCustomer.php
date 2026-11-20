<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;
use Lunar\Models\Customer;
use Lunar\Models\CustomerGroup;

/**
 * The Lunar customer behind a user. Registration creates one; a user created outside it (for example by
 * a seeder or a developer) may have none, and gets one here the first time something needs it (their
 * company details, their address book), in Lunar's default customer group so pricing is unchanged.
 */
final class EnsureCustomer
{
    public function __invoke(User $user): Customer
    {
        $existing = $user->latestCustomer();

        if ($existing !== null) {
            return $existing;
        }

        $customer = Customer::create(['first_name' => $user->name, 'last_name' => '-']);

        $user->customers()->attach($customer);

        if ($group = CustomerGroup::getDefault()) {
            $customer->customerGroups()->attach($group);
        }

        return $customer;
    }
}
