<div>
    @if ($breadcrumbs !== [])
        <nav aria-label="Breadcrumb" class="mb-2 text-sm text-ink-muted">
            <ol class="flex flex-wrap gap-1">
                @foreach ($breadcrumbs as $ancestor)
                    <li>
                        <a href="{{ $ancestor['url'] }}" class="underline">{{ $ancestor['name'] }}</a>
                        <span aria-hidden="true">/</span>
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    <x-ui.section-heading :title="$heading" tag="h1" />

    {{-- What the listing is narrowed to, each one removable: a filtered address (/shop/safety-footwear/safety-boots?industry[0]=Construction) says so. --}}
    @if ($activeFilters !== [])
        <ul class="mt-4 flex flex-wrap items-center gap-2" aria-label="Active filters" data-active-filters>
            @foreach ($activeFilters as $filter)
                <li wire:key="active-{{ $filter['kind'] }}-{{ $filter['facet'] }}-{{ $filter['value'] }}">
                    <button
                        type="button"
                        @if ($filter['kind'] === 'facet') wire:click="removeFilter(@js($filter['facet']), @js($filter['value']))"
                        @elseif ($filter['kind'] === 'price') wire:click="removePriceRange"
                        @else wire:click="removeInStockOnly" @endif
                        class="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-1 text-sm text-ink hover:border-brand-teal hover:bg-surface-muted"
                        aria-label="Remove the {{ $filter['title'] }} filter: {{ $filter['label'] }}"
                        data-active-filter="{{ $filter['facet'] }}"
                    >
                        {{ $filter['label'] }}
                        <span aria-hidden="true" class="text-ink-muted">&times;</span>
                    </button>
                </li>
            @endforeach

            <li><button type="button" wire:click="clearFilters" class="text-sm underline" data-clear-all-filters>Clear all</button></li>
        </ul>
    @endif

    <div class="mt-6 grid gap-8 lg:grid-cols-[16.16rem_1fr]">
        <aside aria-label="Filters">
            <details class="lg:hidden" @if ($hasFilters) open @endif>
                <summary class="rounded border border-border-strong px-4 py-2 font-semibold">Filters</summary>
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
                <h2 id="results-heading" class="text-sm text-ink-muted" aria-live="polite">
                    {{ $result->total }} {{ Str::plural('product', $result->total) }}
                </h2>

                {{-- The sort drop-down is drawn by the page, like the one in the home page's category search: no browser arrow, its own chevron, a teal border on hover and a teal ring on focus. --}}
                <div class="flex items-center gap-2 text-sm">
                    <label for="sort" class="text-ink-muted">Sort by</label>
                    <div class="relative" data-sort>
                        <select
                            id="sort"
                            wire:model.live="sort"
                            class="cursor-pointer appearance-none rounded-md border border-border-strong bg-white py-2 pl-3 pr-9 font-semibold text-brand-navy transition-colors hover:border-brand-teal focus:border-brand-teal focus:outline-none focus:ring-2 focus:ring-brand-teal/40"
                            data-sort-select
                        >
                            @foreach ($sorts as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        <svg aria-hidden="true" class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-navy" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" data-sort-chevron><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                    </div>
                </div>
            </div>

            @if ($products === [])
                <p class="mt-8 rounded border border-border bg-surface-muted p-6 text-ink-muted">
                    No products match these filters.
                    @if ($hasFilters)
                        <button type="button" wire:click="clearFilters" class="underline">Clear all filters</button>
                    @endif
                </p>
            @else
                <ul class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5" data-testid="product-grid">
                    @foreach ($products as $product)
                        <x-storefront.product-card :product="$product" wire:key="product-{{ $product->id }}" />
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
                                    'border-brand-blue bg-brand-blue text-white' => $number === $result->page,
                                    'border-border-strong hover:bg-surface-muted' => $number !== $result->page,
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
