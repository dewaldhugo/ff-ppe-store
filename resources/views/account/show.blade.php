<x-layouts.member title="My account" heading="My account">
    <p class="text-ink-muted" data-account-summary>
        Signed in as <strong class="font-semibold text-ink">{{ $user->email }}</strong>. Your orders and invoices are in the menu.
    </p>

    {{-- Payment terms: how this customer can pay, and what is still owed on account. --}}
    <section class="mt-8 rounded border border-border bg-white p-5" aria-labelledby="terms-heading" data-payment-terms>
        <h2 id="terms-heading" class="text-xl font-semibold text-ink">Payment terms</h2>

        <ul class="mt-3 space-y-2 text-sm">
            <li class="flex flex-wrap items-center gap-2"><span class="font-semibold">Card</span><span class="text-ink-muted">Pay by debit or credit card at checkout.</span></li>
            <li class="flex flex-wrap items-center gap-2" data-terms-account>
                <span class="font-semibold">30 day account</span>
                @if ($termsOn)
                    <span class="rounded border border-success bg-success-surface px-2 py-0.5 text-xs font-bold text-success" data-terms-on>Enabled</span>
                    <span class="text-ink-muted">Orders on account are invoiced and payable within 30 days.</span>
                @else
                    <span class="rounded border border-border bg-surface-muted px-2 py-0.5 text-xs font-bold text-ink-muted" data-terms-off>Not enabled</span>
                    <span class="text-ink-muted">To buy on 30 day terms, please <a href="{{ route('contact') }}" class="font-semibold text-brand-blue underline">contact us</a>.</span>
                @endif
            </li>
        </ul>

        @if ($termsOn)
            <h3 class="mt-5 text-base font-semibold text-ink">Invoices still to pay</h3>

            @if ($openInvoices->isEmpty())
                <p class="mt-2 text-sm text-ink-muted" data-no-open-invoices>You have nothing to pay on account.</p>
            @else
                <div class="mt-2 overflow-x-auto rounded border border-border">
                    <table class="w-full min-w-[26rem] text-left text-sm" data-open-invoices>
                        <thead class="bg-surface-muted text-xs uppercase tracking-wide text-ink-muted">
                            <tr>
                                <th scope="col" class="px-3 py-2">Invoice</th>
                                <th scope="col" class="px-3 py-2">Due</th>
                                <th scope="col" class="px-3 py-2 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($openInvoices as $open)
                                <tr data-open-invoice>
                                    <th scope="row" class="px-3 py-2 font-semibold"><a href="{{ route('account.invoices.show', $open->order->reference) }}" class="text-brand-blue underline">{{ $open->number() }}</a></th>
                                    <td class="px-3 py-2">
                                        {{ $open->dueDate()->format('j M Y') }}
                                        @if ($open->isOverdue())
                                            <span class="ml-1 rounded border border-danger px-1.5 py-0.5 text-xs font-bold text-danger" data-overdue>Overdue</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ $open->order->total->formatted(trimTrailingZeros: false) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-sm" data-open-total>
                    Total to pay: <strong>{{ (new \Lunar\DataTypes\Price((int) $openInvoices->sum(fn ($open) => $open->order->total->value), $openInvoices->first()->order->currency, 1))->formatted(trimTrailingZeros: false) }}</strong>
                </p>
            @endif
        @endif
    </section>

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
</x-layouts.member>
