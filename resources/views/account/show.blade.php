<x-layouts.account title="My account" heading="My account">
    <p class="text-ink-muted" data-account-summary>
        Signed in as <strong class="font-semibold text-ink">{{ $user->email }}</strong>.
        Orders, addresses and checkout are coming soon.
    </p>

    <section class="mt-8" aria-labelledby="profile-heading">
        <h2 id="profile-heading" class="text-xl font-semibold text-ink">Your details</h2>

        <form method="POST" action="{{ route('account.profile.update') }}" novalidate class="mt-4 grid gap-4 sm:grid-cols-2" data-profile-form>
            @csrf
            @method('PUT')

            <x-ui.field name="first_name" label="First name" :value="$customer?->first_name" autocomplete="given-name" maxlength="100" />
            <x-ui.field name="last_name" label="Last name" :value="$customer?->last_name" autocomplete="family-name" maxlength="100" />
            <x-ui.field name="company_name" label="Company name" :value="$customer?->company_name" autocomplete="organization" maxlength="150" class="sm:col-span-2" />
            <x-ui.field name="vat_number" label="VAT number" :value="$customer?->tax_identifier" :optional="true" maxlength="20" hint="We do not check it with HMRC." />
            <x-ui.field name="phone" label="Phone number" type="tel" :value="$user->phone" :optional="true" autocomplete="tel" maxlength="20" />
            <x-ui.field name="email" label="Email address" type="email" :value="$user->email" autocomplete="email" maxlength="255" class="sm:col-span-2" />
            <x-ui.field name="confirm_password" label="Current password" type="password" :optional="true" autocomplete="current-password" maxlength="255" hint="Only needed if you change your email address." class="sm:col-span-2" />

            <div class="sm:col-span-2">
                <x-ui.button type="submit">Save details</x-ui.button>
            </div>
        </form>
    </section>

    <section class="mt-12 border-t border-border pt-8" aria-labelledby="password-heading">
        <h2 id="password-heading" class="text-xl font-semibold text-ink">Change your password</h2>

        <form method="POST" action="{{ route('account.password.update') }}" novalidate class="mt-4 grid max-w-md gap-4" data-password-form>
            @csrf
            @method('PUT')

            <x-ui.field name="current_password" label="Current password" type="password" autocomplete="current-password" maxlength="255" />
            <x-ui.field name="password" label="New password" type="password" autocomplete="new-password" maxlength="255" hint="At least 10 characters, with letters and numbers." />
            <x-ui.field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" maxlength="255" />

            <div>
                <x-ui.button type="submit">Change password</x-ui.button>
            </div>
        </form>
    </section>

    <form method="POST" action="{{ route('logout') }}" class="mt-12 border-t border-border pt-8">
        @csrf
        <x-ui.button-outline type="submit">Log out</x-ui.button-outline>
    </form>
</x-layouts.account>
