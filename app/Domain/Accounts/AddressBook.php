<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Models\Address;
use Lunar\Models\Country;

/**
 * A customer's saved delivery addresses, kept as Lunar addresses on their Lunar customer
 * (`lunar_addresses`). Every method takes the signed-in user and only ever touches that customer's own
 * addresses, so an id from a request can never reach someone else's. One address is the default for
 * delivery (`shipping_default`), and checkout starts from it. The company address given at registration is
 * the billing address and is also the first saved address, so it is there to pick at once.
 */
final class AddressBook
{
    public const int MAX = 25;

    /**
     * @return Collection<int, Address>
     */
    public function all(User $user): Collection
    {
        $customer = $user->latestCustomer();

        if ($customer === null) {
            return new Collection;
        }

        return $customer->addresses()->orderByDesc('shipping_default')->orderBy('id')->get();
    }

    /** A saved address of this customer, or null: never another customer's. */
    public function find(User $user, int $id): ?Address
    {
        return $this->all($user)->firstWhere('id', $id);
    }

    /** The address checkout starts with: the default for delivery, else the billing one, else the first. */
    public function defaultFor(User $user): ?Address
    {
        $all = $this->all($user);

        return $all->firstWhere('shipping_default', true) ?? $all->firstWhere('billing_default', true) ?? $all->first();
    }

    /**
     * Creates the address, or updates it when $address is given. `make_default` (or being the first one)
     * makes it the default for delivery.
     *
     * @param  array{label?: ?string, first_name: string, last_name: string, company_name?: ?string, line_one: string, line_two?: ?string, city: string, county?: ?string, postcode: string, phone?: ?string, delivery_instructions?: ?string, make_default?: bool}  $data
     */
    public function save(User $user, array $data, ?Address $address = null): Address
    {
        return DB::transaction(function () use ($user, $data, $address): Address {
            $customer = app(EnsureCustomer::class)($user);

            $address ??= new Address(['customer_id' => $customer->id]);

            $address->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'company_name' => $data['company_name'] ?? null,
                'line_one' => $data['line_one'],
                'line_two' => $data['line_two'] ?? null,
                'city' => $data['city'],
                'state' => $data['county'] ?? null,
                'postcode' => Postcode::normalise($data['postcode']) ?? $data['postcode'],
                'country_id' => $address->country_id ?: Country::query()->where('iso2', 'GB')->value('id'),
                'contact_email' => $address->contact_email ?: $user->email,
                'contact_phone' => $data['phone'] ?? null,
                'delivery_instructions' => $data['delivery_instructions'] ?? null,
                'meta' => array_merge((array) $address->meta, ['label' => filled($data['label'] ?? null) ? $data['label'] : null]),
            ]);
            $address->save();

            $isFirst = $customer->addresses()->where('shipping_default', true)->doesntExist();

            if (($data['make_default'] ?? false) || $isFirst) {
                $this->makeDefault($address);
            }

            return $address->refresh();
        });
    }

    /** Makes this the one default address for delivery. */
    public function makeDefault(Address $address): void
    {
        DB::transaction(function () use ($address): void {
            Address::query()->where('customer_id', $address->customer_id)->whereKeyNot($address->id)->update(['shipping_default' => false]);
            $address->forceFill(['shipping_default' => true])->save();
        });
    }

    /** Deletes the address; when it was the default, the oldest remaining one becomes the default. */
    public function delete(User $user, Address $address): void
    {
        DB::transaction(function () use ($user, $address): void {
            $wasDefault = (bool) $address->shipping_default;
            $address->delete();

            if ($wasDefault && ($next = $this->all($user)->first())) {
                $this->makeDefault($next);
            }
        });
    }

    /**
     * Saves a delivery address typed at checkout, unless the customer already has one with the same
     * first line and postcode. Returns the new address, or null when it was already saved or the book is full.
     *
     * @param  array<string, ?string>  $fields  line_one, line_two, city, county, postcode.
     * @param  array{full_name: string, company_name: string, phone: string, notes: ?string}  $contact
     */
    public function rememberFromCheckout(User $user, array $fields, array $contact): ?Address
    {
        $all = $this->all($user);
        $postcode = Postcode::normalise($fields['postcode']) ?? $fields['postcode'];

        $already = $all->contains(fn (Address $saved): bool => Str::lower(trim((string) $saved->line_one)) === Str::lower(trim((string) $fields['line_one']))
            && (string) $saved->postcode === $postcode);

        if ($already || $all->count() >= self::MAX) {
            return null;
        }

        $name = trim($contact['full_name']);

        return $this->save($user, [
            'first_name' => Str::before($name, ' '),
            'last_name' => Str::contains($name, ' ') ? Str::after($name, ' ') : '-',
            'company_name' => $contact['company_name'],
            'line_one' => (string) $fields['line_one'],
            'line_two' => $fields['line_two'] ?: null,
            'city' => (string) $fields['city'],
            'county' => $fields['county'] ?: null,
            'postcode' => $postcode,
            'phone' => $contact['phone'],
            'delivery_instructions' => $contact['notes'],
        ]);
    }

    /** A short name for a saved address: the label it was given, else its first line and postcode. */
    public static function label(Address $address): string
    {
        $label = $address->meta['label'] ?? null;

        return filled($label) ? (string) $label : trim($address->line_one.', '.$address->postcode);
    }
}
