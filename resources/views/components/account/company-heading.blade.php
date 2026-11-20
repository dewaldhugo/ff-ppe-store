{{--
    A small heading at the top of every account page: the signed-in member's company name and,
    when staff have set one in the admin panel (Customers, Edit), their company logo. It reads the
    signed-in `web` customer itself, so a page cannot show another customer's company. Nothing is
    shown when there is neither a name nor a logo.
--}}
@php
    $companyCustomer = auth('web')->user()?->latestCustomer();
    $companyName = trim((string) $companyCustomer?->company_name);
    $companyLogo = app(\App\Domain\Accounts\CustomerLogo::class)->url($companyCustomer);
@endphp

@if ($companyName !== '' || $companyLogo)
    <div {{ $attributes->class(['flex items-center gap-3 border-b border-border pb-4']) }} data-account-company>
        @if ($companyLogo)
            {{-- Decorative when the name is beside it, so a screen reader does not say the name twice. --}}
            <img src="{{ $companyLogo }}" alt="{{ $companyName !== '' ? '' : 'Company logo' }}" class="h-10 w-auto max-w-[10rem] shrink-0 object-contain" data-account-logo>
        @endif
        @if ($companyName !== '')
            <p class="min-w-0 text-lg font-semibold text-ink" data-account-company-name>{{ $companyName }}</p>
        @endif
    </div>
@endif
