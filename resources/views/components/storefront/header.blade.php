@php
    $links = [
        ['route' => 'brands', 'label' => 'Brands', 'active' => request()->routeIs('brands', 'brands.show')],
        ['route' => 'size-guide', 'label' => 'Size Guide', 'active' => request()->routeIs('size-guide')],
        ['route' => 'technical-features', 'label' => 'Technical Features', 'active' => request()->routeIs('technical-features')],
        ['route' => 'wear-trials', 'label' => 'Wear Trials', 'active' => request()->routeIs('wear-trials')],
        ['route' => 'ppe-health-check', 'label' => 'PPE Health Check', 'active' => request()->routeIs('ppe-health-check')],
        ['route' => 'about', 'label' => 'About', 'active' => request()->routeIs('about')],
        ['route' => 'contact', 'label' => 'Contact', 'active' => request()->routeIs('contact')],
    ];
    // The page you are on gets brand-blue text and a brand-teal underline. Every link reserves the same
    // 4px border, so nothing shifts when the current one changes.
    $navLink = fn (bool $active): string => 'inline-block border-b-4 py-3 font-semibold hover:text-brand-blue '
        .($active ? 'border-brand-teal text-brand-blue' : 'border-transparent text-ink');
    $productsActive = request()->routeIs('shop', 'category', 'product');
    $drawerLink = fn (bool $active): string => 'block py-3 font-semibold '.($active ? 'text-brand-blue' : 'text-ink');
    $phoneHref = \App\Support\Store::phoneHref();
    $emailHref = \App\Support\Store::emailHref();
@endphp

<header
    class="relative z-30"
    x-data="{ drawer: false, mega: false }"
    x-on:keydown.escape.window="if (mega) { mega = false; $refs.productsButton.focus(); } drawer = false;"
>
    {{-- Top bar --}}
    <div class="surface-dark bg-brand-charcoal text-xs text-white sm:text-sm">
        <x-ui.container class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 py-2">
            @php
                // Small buttons on the dark grey bar: outline for the main action, teal fill for Register.
                $barButton = 'inline-flex items-center rounded-md border px-3 py-1 font-semibold transition-colors';
                $barOutline = $barButton.' border-white text-white hover:bg-white hover:text-brand-navy';
                $barFilled = $barButton.' border-brand-teal bg-brand-teal text-brand-navy hover:border-white hover:bg-white';
            @endphp

            <ul class="flex flex-wrap items-center gap-x-5" data-top-bar-contact>
                <li class="flex items-center gap-1.5">
                    <svg aria-hidden="true" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" data-top-bar-icon="phone"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>
                    @if ($phoneHref)
                        <a href="{{ $phoneHref }}" class="hover:underline">{{ \App\Support\Store::phone() }}</a>
                    @else
                        <span data-store-phone>{{ \App\Support\Store::phone() }}</span>
                    @endif
                </li>
                <li class="hidden items-center gap-1.5 sm:flex">
                    <svg aria-hidden="true" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" data-top-bar-icon="email"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                    @if ($emailHref)
                        <a href="{{ $emailHref }}" class="hover:underline">{{ \App\Support\Store::email() }}</a>
                    @else
                        <span data-store-email>{{ \App\Support\Store::email() }}</span>
                    @endif
                </li>
            </ul>

            <ul class="flex flex-wrap items-center gap-x-2" data-top-bar-account>
                @if ($customer)
                    <li class="flex max-w-[16rem] items-center gap-2 sm:max-w-sm" data-account-name>
                        <svg aria-hidden="true" class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6.75a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0"/></svg>
                        <span class="truncate font-semibold" title="{{ $customer->name }}{{ $companyName ? ' ['.$companyName.']' : '' }}">{{ $customer->name }}@if ($companyName) <span class="font-normal">[{{ $companyName }}]</span>@endif</span>
                    </li>
                    {{-- The account menu. Without JavaScript "My account" is a plain link to the account page, which has the same menu. --}}
                    <li class="relative" x-data="{ open: false }" x-on:keydown.escape="open = false" x-on:click.outside="open = false" data-account-menu>
                        <a
                            href="{{ route('account') }}"
                            class="{{ $barOutline }} after:ml-1.5 after:content-['▾']"
                            aria-haspopup="menu"
                            x-bind:aria-expanded="open.toString()"
                            x-on:click.prevent="open = ! open"
                            data-account-link>My account</a>
                        {{-- A div, not a list: the top bar is a list of lists. --}}
                        <div x-show="open" x-cloak role="menu" class="absolute right-0 top-full z-50 mt-2 w-48 rounded-md border border-border bg-white py-1 text-sm text-ink shadow-lg" data-account-menu-list>
                            <a role="menuitem" href="{{ route('account') }}" class="block px-4 py-2 hover:bg-surface-muted" data-menu-details>My details</a>
                            <a role="menuitem" href="{{ route('account.orders') }}" class="block px-4 py-2 hover:bg-surface-muted" data-menu-orders>Orders</a>
                            <a role="menuitem" href="{{ route('account.invoices') }}" class="block px-4 py-2 hover:bg-surface-muted" data-menu-invoices>Invoices</a>
                            <a role="menuitem" href="{{ route('account.addresses') }}" class="block px-4 py-2 hover:bg-surface-muted" data-menu-addresses>Addresses</a>
                            <a role="menuitem" href="{{ route('favourites') }}" class="block px-4 py-2 hover:bg-surface-muted" data-menu-favourites>Favourites</a>
                            <div class="my-1 border-t border-border"></div>
                            <a role="menuitem" href="{{ route('delivery') }}" class="block px-4 py-2 text-ink-muted hover:bg-surface-muted" data-menu-delivery>Delivery information</a>
                            <a role="menuitem" href="{{ route('returns') }}" class="block px-4 py-2 text-ink-muted hover:bg-surface-muted" data-menu-returns>Returns and refunds</a>
                        </div>
                    </li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="{{ $barOutline }}" data-logout-button>Log out</button>
                        </form>
                    </li>
                @else
                    <li><a href="{{ route('login') }}" class="{{ $barOutline }}" data-login-link>Log in</a></li>
                    <li><a href="{{ route('register') }}" class="{{ $barFilled }}" data-register-link>Register</a></li>
                @endif
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

            {{-- Guests cannot see prices or order, so they get no cart. --}}
            @if ($customer)
            {{-- Saved products. The count is kept current by the heart buttons (favourites-updated). --}}
            <a
                href="{{ route('favourites') }}"
                class="relative ml-auto inline-flex h-11 w-11 items-center justify-center rounded-md text-brand-blue hover:bg-surface-muted lg:ml-0"
                aria-label="Favourites, {{ $favouriteCount }} saved"
                data-favourites-link
                x-data="{ count: {{ $favouriteCount }} }"
                x-on:favourites-updated.window="count = $event.detail.count"
                x-bind:aria-label="'Favourites, ' + count + ' saved'"
            >
                <svg aria-hidden="true" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 20.5s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.6a4.3 4.3 0 0 1 7.5 2.7c0 5.6-7.5 10.2-7.5 10.2Z"/></svg>
                <span aria-hidden="true" data-favourites-count x-show="count > 0" x-text="count" @if ($favouriteCount === 0) style="display: none" @endif class="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-teal px-1 text-xs font-bold text-brand-navy">{{ $favouriteCount }}</span>
            </a>

            {{--
                Hovering or focusing the icon drops down the mini cart (large screens; on a touch screen
                the icon is simply a link). The panel sits flush against the icon, so the pointer can
                cross to it without the wrapper's mouseleave firing.
            --}}
            <div
                class="relative lg:ml-0"
                x-data="{ open: false }"
                x-on:mouseenter="open = true; Livewire.dispatch('mini-cart-open')"
                x-on:mouseleave="open = false"
                x-on:focusin="open = true; Livewire.dispatch('mini-cart-open')"
                x-on:focusout="if (! $el.contains($event.relatedTarget)) open = false"
                x-on:keydown.escape="open = false"
                data-mini-cart-wrapper
            >
            <a
                href="{{ route('cart') }}"
                class="relative inline-flex h-11 w-11 items-center justify-center rounded-md text-brand-blue hover:bg-surface-muted lg:ml-0"
                aria-label="Cart, {{ $cartCount }} {{ Str::plural('item', $cartCount) }}"
                data-cart-link
                {{-- The product page and the cart page announce the new count with a cart-updated event. --}}
                x-data="{ count: {{ $cartCount }} }"
                x-on:cart-updated.window="count = $event.detail.count"
                x-bind:aria-label="'Cart, ' + count + (count === 1 ? ' item' : ' items')"
            >
                <svg aria-hidden="true" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l-1 13H7L6 7Zm3 0V6a3 3 0 0 1 6 0v1"/></svg>
                <span aria-hidden="true" data-cart-count x-text="count" class="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-teal px-1 text-xs font-bold text-brand-navy">{{ $cartCount }}</span>
            </a>

            {{-- The cart page is already open when you are on it. --}}
            @unless (request()->routeIs('cart'))
                <div x-show="open" x-cloak class="absolute right-0 top-full z-40 pt-2 max-lg:hidden" data-mini-cart-panel>
                    <livewire:cart.mini-cart />
                </div>
            @endunless
            </div>
            @endif
        </x-ui.container>
    </div>

    {{-- Main navigation. Below the lg breakpoint it is the drawer; without JavaScript the plain links show at every size. --}}
    <nav aria-label="Main" class="relative hidden border-b border-border bg-surface-nav no-js:block lg:block">
        <x-ui.container>
            <ul class="flex flex-wrap items-center gap-x-8">
                <li>
                    <a href="{{ route('home') }}" class="{{ $navLink(request()->routeIs('home')) }}" @if (request()->routeIs('home')) aria-current="page" @endif>Home</a>
                </li>

                <li>
                    <button
                        type="button"
                        class="no-js:hidden {{ $navLink($productsActive) }} inline-flex items-center gap-1"
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
                    {{-- Only without JavaScript. It must not share $navLink: its inline-block would override hidden. --}}
                    <a href="{{ route('shop') }}" class="hidden no-js:inline-block border-b-4 py-3 font-semibold hover:text-brand-blue {{ $productsActive ? 'border-brand-teal text-brand-blue' : 'border-transparent text-ink' }}" data-products-link>Products</a>
                </li>

                @foreach ($links as $link)
                    <li>
                        <a href="{{ route($link['route']) }}" class="{{ $navLink($link['active']) }}" @if ($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>
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
            class="absolute inset-x-0 top-full border-t-2 border-brand-teal bg-surface-nav shadow-lg"
        >
            <x-ui.container class="py-6">
                <p class="mb-4"><a href="{{ route('shop') }}" class="font-semibold text-brand-blue hover:underline">All products</a></p>

                <ul class="grid gap-x-5 gap-y-5 sm:grid-cols-2 lg:grid-cols-5" data-menu-categories>
                    @foreach ($categories as $category)
                        <li>
                            <a href="{{ $category['url'] }}" class="flex items-baseline justify-between gap-3 text-sm font-semibold text-ink hover:text-brand-blue hover:underline">
                                <span>{{ $category['name'] }}</span>
                                @if (isset($categoryCounts[$category['id']]))
                                    <span class="text-xs font-normal text-ink-muted" data-count>{{ $categoryCounts[$category['id']] }}</span>
                                @endif
                            </a>
                            @if ($category['children'] !== [])
                                <ul class="mt-1.5 space-y-1">
                                    @foreach ($category['children'] as $child)
                                        <li>
                                            <a href="{{ $child['url'] }}" class="flex items-baseline justify-between gap-3 text-sm text-ink-muted hover:text-brand-blue hover:underline">
                                                <span>{{ $child['name'] }}</span>
                                                @if (isset($categoryCounts[$child['id']]))
                                                    <span class="text-xs" data-count>{{ $categoryCounts[$child['id']] }}</span>
                                                @endif
                                            </a>
                                        </li>
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
                            {{-- The same link as the desktop Products menu. --}}
                            <li><a href="{{ route('shop') }}" class="block py-2 font-semibold text-brand-blue" data-drawer-all-products>All products</a></li>
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

                    @foreach ([...$links, ['route' => 'account', 'label' => 'Account', 'active' => request()->routeIs('account')]] as $link)
                        <li><a href="{{ route($link['route']) }}" class="{{ $drawerLink($link['active']) }}" @if ($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
    </div>
</header>
