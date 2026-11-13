<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title.' | '.config('app.name') : config('app.name') }}</title>

    @isset($description)
        <meta name="description" content="{{ $description }}">
    @endisset

    @isset($canonical)
        <link rel="canonical" href="{{ $canonical }}">
    @endisset

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{ $head ?? '' }}
</head>
<body class="flex min-h-screen flex-col bg-white text-slate-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:shadow">
        Skip to main content
    </a>

    <x-storefront.header />

    <main id="main" class="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-7xl px-4 py-6 text-sm text-slate-600">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. Personal protective equipment delivered across the UK.</p>
            <p class="mt-1">Prices are shown {{ app(\App\Domain\Pricing\PriceDisplay::class)->mode()->label() }}. Use the toggle at the top of the page to change this.</p>
        </div>
    </footer>
</body>
</html>
