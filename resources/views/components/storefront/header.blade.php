@php
    $links = [
        ['route' => 'size-guide', 'label' => 'Size Guide', 'active' => request()->routeIs('size-guide')],
        ['route' => 'technical-features', 'label' => 'Technical Features', 'active' => request()->routeIs('technical-features')],
        ['route' => 'about', 'label' => 'About', 'active' => request()->routeIs('about')],
        ['route' => 'contact', 'label' => 'Contact', 'active' => request()->routeIs('contact')],
    ];
    $linkClass = 'inline-block py-3 font-semibold text-ink hover:text-brand-blue';
    $phoneHref = \App\Support\Store::phoneHref();
    $emailHref = \App\Support\Store::emailHref();
@endphp

<header
    class="relative z-30"
    x-data="{ drawer: false, mega: false }"
    x-on:keydown.escape.window="if (mega) { mega = false; $refs.productsButton.focus(); } drawer = false;"
>
    {{-- Top bar --}}
    <div class="surface-dark bg-brand-navy text-xs text-white sm:text-sm">
        <x-ui.container class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 py-2">
            <p>Trade supplier: all prices exclude VAT</p>

            <ul class="flex flex-wrap items-center gap-x-5">
                <li>
                    @if ($phoneHref)
                        <a href="{{ $phoneHref }}" class="hover:underline">{{ \App\Support\Store::phone() }}</a>
                    @else
                        <span data-store-phone>{{ \App\Support\Store::phone() }}</span>
                    @endif
                </li>
                <li class="hidden sm:block">
                    @if ($emailHref)
                        <a href="{{ $emailHref }}" class="hover:underline">{{ \App\Support\Store::email() }}</a>
                    @else
                        <span data-store-email>{{ \App\Support\Store::email() }}</span>
                    @endif
                </li>
                <li><a href="{{ route('account') }}" class="hover:underline">Account</a></li>
            </ul>
        </x-ui.container>
    </div>

    {{-- Main bar --}}
    <div class="border-b border-border bg-white">
        <x-ui.container class="flex flex-wrap items-center gap-x-3 gap-y-3 py-4 sm:gap-x-6">
            <button
                type="button"
                class="no-js:hidden -ml-2 inline-flex h-11 w-11 items-center justify-center rounded-md text-brand-blue hover:bg-surface-muted lg:hidden"
                aria-label="Open menu"
                aria-controls="mobile-menu"
                aria-expanded="false"
                x-bind:aria-expanded="drawer.toString()"
                x-on:click="drawer = true"
                x-ref="drawerButton"
            >
                <svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <a href="{{ route('home') }}" class="shrink-0" data-site-logo>
                <x-storefront.logo />
            </a>

            <form action="{{ route('search') }}" method="get" role="search" class="order-last flex w-full gap-2 lg:order-none lg:w-auto lg:flex-1">
                <label for="site-search" class="sr-only">Search products</label>
                <input
                    id="site-search"
                    type="search"
                    name="q"
                    value="{{ request()->routeIs('search') ? request('q') : '' }}"
                    placeholder="Search by name, brand or safety class"
                    class="w-full rounded-md border border-border-strong px-3 py-2.5 text-sm"
                >
                <x-ui.button type="submit" class="px-5 py-2.5 text-sm">Search</x-ui.button>
            </form>

            <a
                href="{{ route('cart') }}"
                class="relative ml-auto inline-flex h-11 w-11 items-center justify-center rounded-md text-brand-blue hover:bg-surface-muted lg:ml-0"
                aria-label="Cart, {{ $cartCount }} {{ Str::plural('item', $cartCount) }}"
                data-cart-link
            >
                <svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7Zm3 0V6a3 3 0 0 1 6 0v1"/></svg>
                <span aria-hidden="true" data-cart-count class="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-teal px-1 text-xs font-bold text-brand-navy">{{ $cartCount }}</span>
            </a>
        </x-ui.container>
    </div>

    {{-- Main navigation. Below the lg breakpoint it is the drawer; without JavaScript the plain links show at every size. --}}
    <nav aria-label="Main" class="relative hidden border-b border-border bg-surface-nav no-js:block lg:block">
        <x-ui.container>
            <ul class="flex flex-wrap items-center gap-x-8">
                <li>
                    <a href="{{ route('home') }}" class="{{ $linkClass }}" @if (request()->routeIs('home')) aria-current="page" @endif>Home</a>
                </li>

                <li>
                    <button
                        type="button"
                        class="no-js:hidden {{ $linkClass }} inline-flex items-center gap-1"
                        aria-expanded="false"
                        aria-controls="products-menu"
                        x-bind:aria-expanded="mega.toString()"
                        x-on:click="mega = ! mega"
                        x-ref="productsButton"
                        data-products-button
                    >
                        Products
                        <svg aria-hidden="true" class="h-4 w-4 transition-transform" x-bind:class="mega && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                    </button>
                    {{-- Only without JavaScript. It must not share $linkClass: its inline-block would override hidden. --}}
                    <a href="{{ route('shop') }}" class="hidden no-js:inline-block py-3 font-semibold text-ink hover:text-brand-blue" data-products-link>Products</a>
                </li>

                @foreach ($links as $link)
                    <li>
                        <a href="{{ route($link['route']) }}" class="{{ $linkClass }}" @if ($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>

        {{-- The Products menu: every top-level category with its children. --}}
        <div
            id="products-menu"
            x-show="mega"
            x-cloak
            x-on:click.outside="mega = false"
            class="absolute inset-x-0 top-full border-t-2 border-brand-teal bg-white shadow-lg"
        >
            <x-ui.container class="py-8">
                <p class="mb-6"><a href="{{ route('shop') }}" class="font-semibold text-brand-blue hover:underline">All products</a></p>

                <ul class="grid gap-x-8 gap-y-6 sm:grid-cols-2 lg:grid-cols-4" data-menu-categories>
                    @foreach ($categories as $category)
                        <li>
                            <a href="{{ $category['url'] }}" class="font-semibold text-ink hover:text-brand-blue hover:underline">{{ $category['name'] }}</a>
                            @if ($category['children'] !== [])
                                <ul class="mt-2 space-y-1">
                                    @foreach ($category['children'] as $child)
                                        <li><a href="{{ $child['url'] }}" class="text-sm text-ink-muted hover:text-brand-blue hover:underline">{{ $child['name'] }}</a></li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.container>
        </div>
    </nav>

    {{-- Mobile drawer. x-show and x-trap sit on the same element, as Alpine's focus plugin expects. --}}
    <div x-show="drawer" x-cloak class="fixed inset-0 z-40 bg-brand-navy/60 lg:hidden" aria-hidden="true" x-on:click="drawer = false"></div>

    <div
        id="mobile-menu"
        role="dialog"
        aria-modal="true"
        aria-label="Menu"
        x-show="drawer"
        x-cloak
        x-trap.noscroll="drawer"
        class="fixed inset-y-0 left-0 z-50 w-full max-w-sm overflow-y-auto bg-white p-6 shadow-xl lg:hidden"
    >
            <div class="flex items-center justify-between">
                <x-storefront.logo />
                <button
                    type="button"
                    class="-mr-2 inline-flex h-11 w-11 items-center justify-center rounded-md text-brand-blue hover:bg-surface-muted"
                    aria-label="Close menu"
                    x-on:click="drawer = false"
                >
                    <svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <nav aria-label="Mobile" class="mt-6">
                <ul class="divide-y divide-border text-lg">
                    <li><a href="{{ route('home') }}" class="block py-3 font-semibold text-ink">Home</a></li>

                    <li>
                        <a href="{{ route('shop') }}" class="block py-3 font-semibold text-ink">Products</a>

                        <ul class="mb-3 space-y-1 border-l-2 border-brand-teal pl-4 text-base" data-drawer-categories>
                            @foreach ($categories as $category)
                                <li x-data="{ open: false }">
                                    @if ($category['children'] !== [])
                                        <button
                                            type="button"
                                            class="flex w-full items-center justify-between py-2 text-left font-semibold text-ink"
                                            aria-expanded="false"
                                            aria-controls="drawer-category-{{ $loop->index }}"
                                            x-bind:aria-expanded="open.toString()"
                                            x-on:click="open = ! open"
                                        >
                                            {{ $category['name'] }}
                                            <svg aria-hidden="true" class="h-4 w-4 transition-transform" x-bind:class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                                        </button>

                                        <ul id="drawer-category-{{ $loop->index }}" x-show="open" x-cloak class="space-y-1 pb-2 pl-4 text-ink-muted">
                                            <li><a href="{{ $category['url'] }}" class="block py-1.5 font-semibold text-brand-blue">All {{ $category['name'] }}</a></li>
                                            @foreach ($category['children'] as $child)
                                                <li><a href="{{ $child['url'] }}" class="block py-1.5">{{ $child['name'] }}</a></li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <a href="{{ $category['url'] }}" class="block py-2 font-semibold text-ink">{{ $category['name'] }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </li>

                    <li><a href="{{ route('size-guide') }}" class="block py-3 font-semibold text-ink">Size Guide</a></li>
                    <li><a href="{{ route('technical-features') }}" class="block py-3 font-semibold text-ink">Technical Features</a></li>
                    <li><a href="{{ route('about') }}" class="block py-3 font-semibold text-ink">About</a></li>
                    <li><a href="{{ route('contact') }}" class="block py-3 font-semibold text-ink">Contact</a></li>
                    <li><a href="{{ route('account') }}" class="block py-3 font-semibold text-ink">Account</a></li>
                </ul>
            </nav>
    </div>
</header>
