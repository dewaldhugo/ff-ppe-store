<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Accounts\AddressBook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\AddressRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Lunar\Models\Address;

/**
 * My Account, Addresses: the customer's saved delivery addresses. Every address is looked up through the
 * signed-in customer's own address book (AddressBook::find), so an id for anyone else's address is a 404.
 * No request carries a customer id.
 */
final class AddressController extends Controller
{
    public function __construct(private readonly AddressBook $book) {}

    public function index(Request $request): View
    {
        return view('account.addresses.index', ['addresses' => $this->book->all($this->user($request)), 'book' => $this->book]);
    }

    public function create(Request $request): View
    {
        return view('account.addresses.form', [
            'address' => null,
            'defaults' => ['first_name' => $this->firstName($request), 'make_default' => $this->book->all($this->user($request))->isEmpty()],
        ]);
    }

    public function store(AddressRequest $request): RedirectResponse
    {
        $user = $this->user($request);

        if ($this->book->all($user)->count() >= AddressBook::MAX) {
            return redirect()->route('account.addresses')->with('status', 'You can save up to '.AddressBook::MAX.' addresses. Delete one to add another.');
        }

        $this->book->save($user, $request->validated());

        return redirect()->route('account.addresses')->with('status', 'Address saved.');
    }

    public function edit(Request $request, int $addressId): View
    {
        $address = $this->owned($request, $addressId);

        return view('account.addresses.form', [
            'address' => $address,
            'defaults' => [
                'label' => $address->meta['label'] ?? null,
                'first_name' => $address->first_name,
                'last_name' => $address->last_name,
                'company_name' => $address->company_name,
                'line_one' => $address->line_one,
                'line_two' => $address->line_two,
                'city' => $address->city,
                'county' => $address->state,
                'postcode' => $address->postcode,
                'phone' => $address->contact_phone,
                'delivery_instructions' => $address->delivery_instructions,
                'make_default' => (bool) $address->shipping_default,
            ],
        ]);
    }

    public function update(AddressRequest $request, int $addressId): RedirectResponse
    {
        $address = $this->owned($request, $addressId);

        $this->book->save($this->user($request), $request->validated(), $address);

        return redirect()->route('account.addresses')->with('status', 'Address updated.');
    }

    public function destroy(Request $request, int $addressId): RedirectResponse
    {
        $this->book->delete($this->user($request), $this->owned($request, $addressId));

        return redirect()->route('account.addresses')->with('status', 'Address deleted.');
    }

    public function makeDefault(Request $request, int $addressId): RedirectResponse
    {
        $this->book->makeDefault($this->owned($request, $addressId));

        return redirect()->route('account.addresses')->with('status', 'Default delivery address changed.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('web');

        return $user;
    }

    private function owned(Request $request, int $id): Address
    {
        return $this->book->find($this->user($request), $id) ?? abort(404);
    }

    private function firstName(Request $request): ?string
    {
        return explode(' ', trim((string) $this->user($request)->name))[0] ?: null;
    }
}
