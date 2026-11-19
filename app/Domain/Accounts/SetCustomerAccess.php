<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;
use App\Notifications\CustomerAccountActivated;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Customer;
use Throwable;

/**
 * Staff decisions about a customer's storefront access, applied to every login attached to the
 * Lunar customer: whether the login is active, and whether 30 day account terms are on.
 *
 * Activating a login that was inactive marks its email address verified (staff have vetted the
 * business, and the activation email goes to that address) and emails the login details. A
 * failed email is reported and never undoes the activation; staff can resend it by switching
 * the login off and on. Deactivating signs the customer out on their next request
 * (EnsureLoginActive).
 */
final class SetCustomerAccess
{
    public function __invoke(Customer $customer, bool $active, bool $accountTerms): void
    {
        $activated = [];

        DB::transaction(function () use ($customer, $active, $accountTerms, &$activated): void {
            foreach ($customer->users()->get() as $user) {
                /** @var User $user */
                $wasApproved = $user->isApproved();

                $user->account_terms = $accountTerms;
                $user->approved_at = $active ? ($user->approved_at ?? now()) : null;

                if ($active && $user->email_verified_at === null) {
                    $user->email_verified_at = now();
                }

                $user->save();

                if ($active && ! $wasApproved) {
                    $activated[] = $user;
                }
            }
        });

        // After the commit, so a mail failure cannot roll the activation back.
        foreach ($activated as $user) {
            try {
                $user->notify(new CustomerAccountActivated);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
