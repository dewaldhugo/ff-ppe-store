@props(['title', 'heading', 'intro' => null])

{{--
    The storefront layout for the signed-in member's pages (My account, Orders, Invoices): noindex, a
    heading, the member menu beside the content (above it on a phone), and one flash message (`status`).
    The login and register pages use layouts.account instead.
--}}
@php
    $links = [
        ['route' => 'account', 'label' => 'My details', 'active' => request()->routeIs('account'), 'key' => 'details'],
        ['route' => 'account.orders', 'label' => 'Orders', 'active' => request()->routeIs('account.orders*'), 'key' => 'orders'],
        ['route' => 'account.invoices', 'label' => 'Invoices', 'active' => request()->routeIs('account.invoices*'), 'key' => 'invoices'],
        ['route' => 'account.addresses', 'label' => 'Addresses', 'active' => request()->routeIs('account.addresses*'), 'key' => 'addresses'],
        ['route' => 'favourites', 'label' => 'Favourites', 'active' => request()->routeIs('favourites'), 'key' => 'favourites'],
    ];

    // Help pages, set apart from the account pages.
    $help = [
        ['route' => 'delivery', 'label' => 'Delivery information', 'key' => 'delivery'],
        ['route' => 'returns', 'label' => 'Returns and refunds', 'key' => 'returns'],
    ];
@endphp

<x-layouts.storefront :title="$title" :noindex="true">
    <div class="py-4">
        <x-ui.section-heading :title="$heading" tag="h1">{{ $intro }}</x-ui.section-heading>

        @if (session('status'))
            <x-ui.alert class="mt-6" data-flash>{{ session('status') }}</x-ui.alert>
        @endif

        <div class="mt-8 grid items-start gap-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
            <nav aria-label="My account" data-member-nav>
                <ul class="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:gap-1 lg:overflow-visible">
                    @foreach ($links as $link)
                        <li class="shrink-0">
                            <a
                                href="{{ route($link['route']) }}"
                                @if ($link['active']) aria-current="page" @endif
                                data-member-link="{{ $link['key'] }}"
                                @class([
                                    'block rounded-md px-3 py-2 text-sm font-semibold',
                                    'bg-brand-blue text-white' => $link['active'],
                                    'text-brand-blue hover:bg-surface-muted' => ! $link['active'],
                                ])
                            >{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                    @foreach ($help as $link)
                        <li class="shrink-0 {{ $loop->first ? 'lg:mt-3 lg:border-t lg:border-border lg:pt-3' : '' }}">
                            <a href="{{ route($link['route']) }}" class="block rounded-md px-3 py-2 text-sm text-ink-muted hover:bg-surface-muted hover:text-brand-blue" data-member-help="{{ $link['key'] }}">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                    <li class="shrink-0 lg:mt-3 lg:border-t lg:border-border lg:pt-3">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-ink-muted hover:bg-surface-muted" data-member-logout>Log out</button>
                        </form>
                    </li>
                </ul>
            </nav>

            <div class="min-w-0">
                {{ $slot }}
            </div>
        </div>
    </div>
</x-layouts.storefront>
