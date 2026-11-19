<!DOCTYPE html>
<html lang="en-GB" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>document.documentElement.classList.remove('no-js')</script>

    <title>{{ isset($title) ? $title.' | '.\App\Support\Store::name() : \App\Support\Store::name() }}</title>

    {{-- Checkout is never indexed, whatever STORE_NOINDEX says. --}}
    <meta name="robots" content="noindex">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- Stripe.js and the card form, only while card payment is switched on (both Stripe keys set). --}}
    @if (app(\App\Domain\Checkout\CardPayments::class)->isConfigured())
        <x-checkout.stripe-scripts />
    @endif
</head>
<body class="min-h-screen bg-surface-muted text-ink antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:shadow">
        Skip to main content
    </a>

    {{-- A clean header: the logo and a way to reach a person. No store navigation to lead the buyer away. --}}
    <header class="border-b border-border bg-white" data-checkout-header>
        <x-ui.container class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 py-4">
            <a href="{{ route('home') }}" data-site-logo><x-storefront.logo /></a>

            <p class="flex flex-wrap items-center gap-x-4 text-sm" data-checkout-support>
                <span class="text-ink-muted">Need help?</span>
                @if ($phone = \App\Support\Store::phoneHref())
                    <a href="{{ $phone }}" class="font-semibold text-brand-blue hover:underline">{{ \App\Support\Store::phone() }}</a>
                @endif
                @if ($email = \App\Support\Store::emailHref())
                    <a href="{{ $email }}" class="font-semibold text-brand-blue hover:underline">{{ \App\Support\Store::email() }}</a>
                @endif
            </p>
        </x-ui.container>
    </header>

    <main id="main">
        <x-ui.container class="py-8">
            {{ $slot }}
        </x-ui.container>
    </main>

    @livewireScripts
</body>
</html>
