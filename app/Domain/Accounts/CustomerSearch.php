<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Lunar\Models\Customer;
use Throwable;

/**
 * Indexes a Lunar customer for the admin search without letting a search outage undo an
 * account change. Scout would otherwise index inside the surrounding transaction and a
 * Meilisearch failure would roll the registration or profile update back.
 */
final class CustomerSearch
{
    /**
     * Saves the customer without indexing it. Run the writes inside a transaction, then
     * call `index()` once it has committed.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutIndexing(callable $callback): mixed
    {
        return Customer::withoutSyncingToSearch($callback);
    }

    /**
     * Indexes the customer after the commit. A failure is reported, never thrown.
     */
    public static function index(?Customer $customer): void
    {
        if ($customer === null) {
            return;
        }

        try {
            $customer->searchable();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
