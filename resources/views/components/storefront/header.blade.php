<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-4">
        <a href="{{ route('home') }}" class="shrink-0" data-site-logo>
            <img
                src="{{ asset('images/the-bridge-group-logo.svg') }}"
                alt="{{ config('app.name') }}"
                width="286"
                height="32"
                class="h-7 w-auto sm:h-8"
            >
        </a>

        <form action="{{ route('search') }}" method="get" role="search" class="order-3 flex w-full gap-2 sm:order-none sm:w-auto sm:flex-1">
            <label for="site-search" class="sr-only">Search products</label>
            <input
                id="site-search"
                type="search"
                name="q"
                value="{{ request()->routeIs('search') ? request('q') : '' }}"
                placeholder="Search by name, brand or safety class"
                class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
            >
            <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Search
            </button>
        </form>

        <form action="{{ route('vat-mode') }}" method="post" class="ml-auto" data-vat-toggle>
            @csrf
            <fieldset class="flex items-center gap-1 text-sm">
                <legend class="sr-only">Show prices including or excluding VAT</legend>
                <span class="mr-1 text-slate-600" aria-hidden="true">Prices</span>
                @foreach ($vatModes as $mode)
                    <button
                        type="submit"
                        name="mode"
                        value="{{ $mode->value }}"
                        aria-pressed="{{ $mode === $vatMode ? 'true' : 'false' }}"
                        @class([
                            'rounded border px-2 py-1 font-medium',
                            'border-slate-900 bg-slate-900 text-white' => $mode === $vatMode,
                            'border-slate-300 bg-white text-slate-700 hover:bg-slate-100' => $mode !== $vatMode,
                        ])
                    >
                        {{ $mode->label() }}
                    </button>
                @endforeach
            </fieldset>
        </form>
    </div>

    @if ($categories !== [])
        <nav aria-label="Categories" class="border-t border-slate-200 bg-slate-50">
            <ul class="mx-auto flex max-w-7xl flex-wrap gap-x-6 gap-y-1 px-4 py-2 text-sm">
                @foreach ($categories as $category)
                    <li class="group relative">
                        <a href="{{ $category['url'] }}" class="inline-block py-1 font-semibold text-slate-900 hover:underline">
                            {{ $category['name'] }}
                        </a>

                        @if ($category['children'] !== [])
                            <ul class="z-10 flex flex-wrap gap-x-4 sm:absolute sm:left-0 sm:top-full sm:hidden sm:min-w-48 sm:flex-col sm:rounded sm:border sm:border-slate-200 sm:bg-white sm:p-2 sm:shadow-lg sm:group-focus-within:flex sm:group-hover:flex">
                                @foreach ($category['children'] as $child)
                                    <li>
                                        <a href="{{ $child['url'] }}" class="inline-block py-1 text-slate-700 hover:underline sm:px-2">
                                            {{ $child['name'] }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif
</header>
