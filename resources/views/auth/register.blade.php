<x-layouts.account title="Register" heading="Register for a Frontline Trade Account">
    <div class="space-y-4 text-ink-muted" data-register-intro>
        <p>Create your trade account to unlock exclusive trade pricing, access real-time stock availability and benefit from the support of a dedicated PPE consultant.</p>
        <p>You can browse our full product catalogue without registering.</p>
        <p class="text-sm" data-register-vat-note>*All prices are quoted excluding VAT.</p>
    </div>

    <form method="POST" action="{{ route('register') }}" novalidate class="mt-8 grid gap-4 sm:grid-cols-2" data-register-form>
        @csrf

        <x-ui.field name="first_name" label="First name" autocomplete="given-name" maxlength="100" />
        <x-ui.field name="last_name" label="Last name" autocomplete="family-name" maxlength="100" />
        <x-ui.field name="company_name" label="Company name" autocomplete="organization" maxlength="150" class="sm:col-span-2" />
        <x-ui.select name="industry" label="Industry" :options="\App\Domain\Accounts\Industries::all()" placeholder="Choose your industry" class="sm:col-span-2" />

        {{-- The company address. The postcode is not checked against the UK format (UK and EU customers). --}}
        <x-ui.field name="address_line_one" label="Company address line 1" autocomplete="address-line1" maxlength="255" class="sm:col-span-2" />
        <x-ui.field name="address_line_two" label="Company address line 2" :optional="true" autocomplete="address-line2" maxlength="255" class="sm:col-span-2" />
        <x-ui.field name="address_city" label="Town or city" autocomplete="address-level2" maxlength="100" />
        <x-ui.field name="address_county" label="County" :optional="true" autocomplete="address-level1" maxlength="100" />
        <x-ui.field name="address_postcode" label="Postcode" autocomplete="postal-code" maxlength="10" />

        <x-ui.field name="vat_number" label="VAT number" :optional="true" maxlength="20" hint="For example GB123456789. We do not check it with HMRC." />
        <x-ui.field name="phone" label="Phone number" type="tel" :optional="true" autocomplete="tel" maxlength="20" />
        <x-ui.field name="email" label="Email address" type="email" autocomplete="email" maxlength="255" class="sm:col-span-2" />
        <x-ui.field name="password" label="Password" type="password" autocomplete="new-password" maxlength="255" hint="At least 10 characters, with letters and numbers." />
        <x-ui.field name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" maxlength="255" />

        <x-storefront.honeypot />

        <p class="text-sm text-ink-muted sm:col-span-2" data-register-terms>
            By registering you agree to our
            <a href="{{ route('terms') }}" class="font-semibold text-brand-blue underline">Terms</a>
            and
            <a href="{{ route('privacy') }}" class="font-semibold text-brand-blue underline">Privacy Policy</a>.
        </p>

        <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
            <x-ui.button type="submit">Create account</x-ui.button>
            <p class="text-sm text-ink">
                Already registered?
                <a href="{{ route('login') }}" class="font-semibold text-brand-blue underline">Log in</a>
            </p>
        </div>
    </form>
</x-layouts.account>
