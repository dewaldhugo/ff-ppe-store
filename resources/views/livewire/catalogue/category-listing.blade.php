<div>
    @if ($breadcrumbs->isNotEmpty())
        <nav aria-label="Breadcrumb" class="mb-2 text-sm text-slate-600">
            <ol class="flex flex-wrap gap-1">
                @foreach ($breadcrumbs as $ancestor)
                    <li>
                        <a href="{{ route('category', $ancestor->defaultUrl?->slug ?? $ancestor->id) }}" class="underline">{{ $ancestor->translateAttribute('name') }}</a>
                        <span aria-hidden="true">/</span>
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    <h1 class="text-2xl font-bold tracking-tight">{{ $heading }}</h1>

    <div class="mt-6 grid gap-8 lg:grid-cols-[16rem_1fr]">
        <aside aria-label="Filters">
            <details class="lg:hidden" @if ($hasFilters) open @endif>
                <summary class="rounded border border-slate-300 px-4 py-2 font-semibold">Filters</summary>
                <div class="mt-4">
                    @include('livewire.catalogue.partials.filters', ['idPrefix' => 'm'])
                </div>
            </details>

            <div class="hidden lg:block">
                @include('livewire.catalogue.partials.filters', ['idPrefix' => 'd'])
            </div>
        </aside>

        <section aria-labelledby="results-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="results-heading" class="text-sm text-slate-600" aria-live="polite">
                    {{ $result->total }} {{ Str::plural('product', $result->total) }}
                </h2>

                <div class="flex items-center gap-2 text-sm">
                    <label for="sort" class="text-slate-600">Sort by</label>
                    <select id="sort" wire:model.live="sort" class="rounded border border-slate-300 px-2 py-1">
                        @foreach ($sorts as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($products === [])
                <p class="mt-8 rounded border border-slate-200 bg-slate-50 p-6 text-slate-700">
                    No products match these filters.
                    @if ($hasFilters)
                        <button type="button" wire:click="clearFilters" class="underline">Clear all filters</button>
                    @endif
                </p>
            @else
                <ul class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4" data-testid="product-grid">
                    @foreach ($products as $product)
                        @php
                            $price = $priceDisplay->fromPrice($product);
                            $inStock = $product->variants->sum('stock') > 0;
                            $prices = $product->variants->flatMap->prices->pluck('price.value')->unique();
                        @endphp
                        <li wire:key="product-{{ $product->id }}" class="relative flex flex-col rounded border border-slate-200 p-3">
                            <div class="relative aspect-square overflow-hidden rounded bg-slate-100">
                                @if ($product->thumbnail)
                                    <img
                                        src="{{ $product->thumbnail->getUrl('thumb') }}"
                                        alt=""
                                        loading="lazy"
                                        width="300"
                                        height="300"
                                        class="h-full w-full object-contain"
                                    >
                                @endif

                                @unless ($inStock)
                                    <span class="absolute left-2 top-2 rounded bg-slate-900 px-2 py-1 text-xs font-semibold text-white">Out of stock</span>
                                @endunless
                            </div>

                            <p class="mt-3 text-xs uppercase tracking-wide text-slate-500">{{ $product->brand?->name }}</p>
                            <h3 class="font-semibold">
                                <a href="{{ route('product', $product->defaultUrl?->slug ?? $product->id) }}" class="after:absolute after:inset-0 hover:underline">
                                    {{ $product->translateAttribute('name') }}
                                </a>
                            </h3>
                            <p class="text-sm text-slate-600">{{ $product->translateAttribute('title') }}</p>

                            @if ($price)
                                <p class="mt-auto pt-2">
                                    @if ($prices->count() > 1)
                                        <span class="text-sm text-slate-600">From</span>
                                    @endif
                                    <span class="font-bold">{{ $price->formatted() }}</span>
                                    <span class="text-xs text-slate-600">{{ $price->suffix() }}</span>
                                    @if ($price->compareFormatted())
                                        <s class="ml-1 text-sm text-slate-500"><span class="sr-only">Was</span> {{ $price->compareFormatted() }}</s>
                                    @endif
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($result->lastPage > 1)
                    <nav aria-label="Pagination" class="mt-8 flex flex-wrap items-center gap-2 text-sm">
                        @for ($number = 1; $number <= $result->lastPage; $number++)
                            <a
                                href="{{ request()->fullUrlWithQuery(['page' => $number]) }}"
                                wire:click.prevent="goToPage({{ $number }})"
                                @if ($number === $result->page) aria-current="page" @endif
                                @class([
                                    'rounded border px-3 py-1',
                                    'border-slate-900 bg-slate-900 text-white' => $number === $result->page,
                                    'border-slate-300 hover:bg-slate-100' => $number !== $result->page,
                                ])
                            >
                                <span class="sr-only">Page</span> {{ $number }}
                            </a>
                        @endfor
                    </nav>
                @endif
            @endif
        </section>
    </div>
</div>
