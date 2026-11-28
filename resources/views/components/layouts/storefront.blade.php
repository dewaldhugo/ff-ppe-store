<!DOCTYPE html>
<html lang="en-GB" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Menus need JavaScript. This tells the stylesheet whether it is there. --}}
    <script>document.documentElement.classList.remove('no-js')</script>

    {{-- fullTitle is used as it stands (the home page); otherwise "Page | Store name". --}}
    <title>{{ isset($fullTitle) ? $fullTitle : (isset($title) ? $title.' | '.\App\Support\Store::name() : \App\Support\Store::name()) }}</title>

    @isset($description)
        <meta name="description" content="{{ $description }}">
    @endisset

    @isset($canonical)
        <link rel="canonical" href="{{ $canonical }}">
    @endisset

    {{-- The site is out of search engines while STORE_NOINDEX is true. A page can also stay
         out once the site is live by passing noindex (the sign-in and account pages do). --}}
    @if (config('store.noindex') || ! empty($noindex))
        <meta name="robots" content="noindex">
    @endif

    <x-storefront.favicons />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{ $head ?? '' }}
</head>
<body class="flex min-h-screen flex-col bg-white text-ink antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:shadow">
        Skip to main content
    </a>

    <x-storefront.header />

    {{-- One-off message after an action, such as registering. --}}
    @if (session('status'))
        <div class="border-b border-border bg-surface-muted">
            <x-ui.container class="py-3">
                <x-ui.alert data-flash>{{ session('status') }}</x-ui.alert>
            </x-ui.container>
        </div>
    @endif

    @if (! empty($full))
        <main id="main" class="flex-1">
            {{ $slot }}
        </main>
    @else
        {{-- overflow-x-clip: a full width band (100vw) must not add a sideways scrollbar. --}}
        <main id="main" class="flex-1 overflow-x-clip">
            <x-ui.container class="py-8">
                {{ $slot }}
            </x-ui.container>
        </main>
    @endif

    <x-storefront.footer />

    <x-storefront.compare-bar />

    @livewireScripts
</body>
</html>
