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

    <div class="mt-6 grid gap-8 lg:grid-cols-[16rem_1fr]">
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

                <div class="flex items-center gap-2 text-sm">
                    <label for="sort" class="text-ink-muted">Sort by</label>
                    <select id="sort" wire:model.live="sort" class="rounded border border-border-strong px-2 py-1">
                        @foreach ($sorts as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
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
                <ul class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4" data-testid="product-grid">
                    @foreach ($products as $product)
                        @php
                            $price = $priceDisplay->fromPrice($product);
                            $inStock = $product->variants->sum('stock') > 0;
                            $prices = $product->variants->flatMap->prices->pluck('price.value')->unique();
                        @endphp
                        <li wire:key="product-{{ $product->id }}" class="group relative flex flex-col rounded border border-border p-3 transition-colors hover:border-brand-teal">
                            <div class="relative aspect-square overflow-hidden rounded bg-surface-muted">
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
                                    <span class="absolute left-2 top-2 rounded bg-brand-blue px-2 py-1 text-xs font-semibold text-white">Out of stock</span>
                                @endunless
                            </div>

                            @php($swatches = $this->swatches($product))
                            @if ($swatches !== [])
                                {{-- Display only: not links, and no colour is marked as selected. --}}
                                <p class="mt-3 flex flex-wrap justify-center gap-1.5" data-swatches>
                                    <span class="sr-only">Colours: {{ implode(', ', array_column($swatches, 'name')) }}</span>
                                    @foreach ($swatches as $swatch)
                                        <x-ui.swatch :hexes="$swatch['hexes']" data-swatch aria-hidden="true" class="h-4 w-4" />
                                    @endforeach
                                </p>
                            @endif

                            <p class="mt-3 text-xs uppercase tracking-wide text-ink-muted">{{ $product->brand?->name }}</p>
                            <h3 class="font-semibold">
                                <a href="{{ route('product', $product->defaultUrl?->slug ?? $product->id) }}" class="after:absolute after:inset-0 hover:underline">
                                    {{ $product->translateAttribute('name') }}
                                </a>
                            </h3>
                            <p class="text-sm text-ink-muted">{{ $product->translateAttribute('title') }}</p>

                            {{-- Where it can be delivered, only while EU delivery is switched on. --}}
                            <x-storefront.ships-to :product="$product" class="mt-2" />

                            {{-- Pinned to the bottom of the card, so every card in a row is the same height and the buttons line up, with or without a price. --}}
                            <div class="mt-auto flex flex-col">
                                @if ($price)
                                    <p class="pt-2" data-price>
                                        @if ($prices->count() > 1)
                                            <span class="text-sm text-ink-muted">From</span>
                                        @endif
                                        <span class="font-bold">{{ $price->formatted() }}</span>
                                        <span class="text-xs text-ink-muted">{{ $price->suffix() }}</span>
                                    </p>
                                @endif

                                <x-ui.card-cta class="mt-3" data-view-product>View product</x-ui.card-cta>
                            </div>
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
