@php
    $editing = $address !== null;
    $title = $editing ? 'Edit address' : 'Add an address';
@endphp

<x-layouts.member :title="$title" :heading="$title">
    <p class="-mt-4 mb-6 text-sm"><a href="{{ route('account.addresses') }}" class="font-semibold text-brand-blue underline">&larr; All addresses</a></p>

    <form
        method="POST"
        action="{{ $editing ? route('account.addresses.update', $address->id) : route('account.addresses.store') }}"
        novalidate
        class="grid gap-4 sm:grid-cols-2"
        data-address-form
    >
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-ui.field name="label" label="A name for this address" :value="$defaults['label'] ?? null" :optional="true" maxlength="60" hint="For example Head office or Depot." class="sm:col-span-2" />
        <x-ui.field name="first_name" label="First name" :value="$defaults['first_name'] ?? null" autocomplete="given-name" maxlength="100" />
        <x-ui.field name="last_name" label="Last name" :value="$defaults['last_name'] ?? null" autocomplete="family-name" maxlength="100" />
        <x-ui.field name="company_name" label="Company name" :value="$defaults['company_name'] ?? null" :optional="true" autocomplete="organization" maxlength="150" class="sm:col-span-2" />
        <x-ui.field name="line_one" label="Address line 1" :value="$defaults['line_one'] ?? null" autocomplete="address-line1" maxlength="150" class="sm:col-span-2" />
        <x-ui.field name="line_two" label="Address line 2" :value="$defaults['line_two'] ?? null" :optional="true" autocomplete="address-line2" maxlength="150" class="sm:col-span-2" />
        <x-ui.field name="city" label="Town or city" :value="$defaults['city'] ?? null" autocomplete="address-level2" maxlength="100" />
        <x-ui.field name="county" label="County" :value="$defaults['county'] ?? null" :optional="true" autocomplete="address-level1" maxlength="100" />
        <x-ui.field name="postcode" label="Postcode" :value="$defaults['postcode'] ?? null" autocomplete="postal-code" maxlength="10" />
        <x-ui.field name="phone" label="Phone number" type="tel" :value="$defaults['phone'] ?? null" :optional="true" autocomplete="tel" maxlength="20" hint="For the courier." />
        <x-ui.field name="delivery_instructions" label="Delivery instructions" :value="$defaults['delivery_instructions'] ?? null" :optional="true" maxlength="500" hint="For example, use the side gate." class="sm:col-span-2" />

        <p class="text-sm text-ink-muted sm:col-span-2">We deliver to addresses in the UK.</p>

        <div class="sm:col-span-2">
            <label class="inline-flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" name="make_default" value="1" @checked(old('make_default', $defaults['make_default'] ?? false)) class="size-4 rounded border-border-strong text-brand-blue focus:ring-2 focus:ring-brand-blue/30">
                Use this as my default delivery address
            </label>
        </div>

        <div class="sm:col-span-2">
            <x-ui.button type="submit">{{ $editing ? 'Save changes' : 'Save address' }}</x-ui.button>
        </div>
    </form>
</x-layouts.member>
