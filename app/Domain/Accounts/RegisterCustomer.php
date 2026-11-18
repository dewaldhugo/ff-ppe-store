<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Country;
use Lunar\Models\Customer;
use Lunar\Models\CustomerGroup;
use Throwable;

/**
 * Registers a customer: one User and one Lunar Customer attached to it, in a single
 * transaction. Lunar never creates customers for users, so this is the only place that does.
 *
 * Side effects that must not undo a registration (search indexing, the verification
 * email) run after the transaction has committed, and a failure in them is reported,
 * never thrown.
 */
final class RegisterCustomer
{
    /**
     * @param  array{first_name: string, last_name: string, company_name: string, address_line_one: string, address_line_two?: ?string, address_city: string, address_county?: ?string, address_postcode: string, industry: string, vat_number?: ?string, phone?: ?string, email: string, password: string}  $data
     */
    public function __invoke(array $data): User
    {
        $customer = null;

        $user = DB::transaction(function () use ($data, &$customer): User {
            $user = User::create([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);

            // Scout would index the customer inside the transaction, so a Meilisearch outage
            // would roll the registration back. Index it after the commit instead.
            $customer = CustomerSearch::withoutIndexing(fn (): Customer => Customer::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'company_name' => $data['company_name'],
                'tax_identifier' => $data['vat_number'] ?? null,
                'meta' => ['industry' => $data['industry']],
            ]));

            $user->customers()->attach($customer);

            // The company address is the customer's billing address. Country is always the UK:
            // the store sells to UK customers only. No Lunar country row leaves the id null.
            $customer->addresses()->create([
                'country_id' => Country::query()->where('iso2', 'GB')->value('id'),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'company_name' => $data['company_name'],
                'line_one' => $data['address_line_one'],
                'line_two' => $data['address_line_two'] ?? null,
                'city' => $data['address_city'],
                'state' => $data['address_county'] ?? null,
                'postcode' => $data['address_postcode'],
                'contact_email' => $data['email'],
                'contact_phone' => $data['phone'] ?? null,
                'billing_default' => true,
            ]);

            $this->attachDefaultGroup($customer);

            return $user;
        });

        CustomerSearch::index($customer);

        try {
            // The framework listens to this and calls User::sendEmailVerificationNotification(),
            // which does nothing while STORE_REQUIRE_EMAIL_VERIFICATION is false.
            event(new Registered($user));
        } catch (Throwable $e) {
            report($e);
        }

        return $user;
    }

    /**
     * Gives the customer Lunar's default customer group, so a signed-in buyer sees the same
     * prices as a guest. Without a default group the customer is still created: pricing
     * falls back to the default group by itself, and the problem is reported.
     */
    private function attachDefaultGroup(Customer $customer): void
    {
        $group = CustomerGroup::getDefault();

        if ($group === null) {
            report(new \RuntimeException('No default customer group exists: customer '.$customer->getKey().' was registered without one.'));

            return;
        }

        $customer->customerGroups()->attach($group);
    }
}
