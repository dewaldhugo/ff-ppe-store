{{--
    /brands. Each tile is a link to that brand's products, so the page works
    without JavaScript. With JavaScript a tile toggles the brand instead, and
    "View products" opens /brands/{slugs} for every selected brand.
--}}
<x-layouts.storefront
    title="Brands"
    description="Shop safety footwear, workwear and PPE by brand. Choose one or more brands to see their products."
    :canonical="route('brands')"
>
    <div
        x-data="{
            selected: [],
            query: '',
            names: @js(array_map(fn ($tile) => Str::lower($tile->name), $tiles)),
            base: @js(route('brands')),
            toggle(slug) {
                this.selected = this.selected.includes(slug)
                    ? this.selected.filter((s) => s !== slug)
                    : [...this.selected, slug];
            },
            isSelected(slug) {
                return this.selected.includes(slug);
            },
            matches(name) {
                return name.includes(this.query.trim().toLowerCase());
            },
            get noMatches() {
                return ! this.names.some((name) => this.matches(name));
            },
            get url() {
                return this.base + '/' + [...this.selected].sort().join(',');
            },
        }"
    >
        <x-ui.section-heading title="Shop by brand" tag="h1">
            Choose one or more brands, then view their products together.
        </x-ui.section-heading>

        <div class="mt-6 max-w-sm no-js:hidden">
            <label for="brand-filter" class="sr-only">Find a brand</label>
            <input
                id="brand-filter"
                type="search"
                x-model="query"
                placeholder="Find a brand"
                autocomplete="off"
                class="w-full rounded border border-border-strong px-3 py-2"
            >
        </div>

        {{-- A bordered grid: the ul draws the top and left edges, every cell the right and bottom. --}}
        <ul
            class="mt-4 grid grid-cols-[repeat(auto-fill,minmax(112px,1fr))] border-l border-t border-border"
            data-testid="brand-grid"
        >
            @foreach ($tiles as $tile)
                <li class="border-b border-r border-border" x-show="matches(@js(Str::lower($tile->name)))" data-brand="{{ $tile->slug }}">
                    <a
                        href="{{ route('brands.show', $tile->slug) }}"
                        x-on:click.prevent="toggle(@js($tile->slug))"
                        x-on:keydown.space.prevent="toggle(@js($tile->slug))"
                        x-bind:role="'button'"
                        x-bind:aria-pressed="isSelected(@js($tile->slug)).toString()"
                        x-bind:class="isSelected(@js($tile->slug)) && 'ring-2 ring-inset ring-brand-blue'"
                        @class([
                            'group relative flex aspect-[3/2] w-full flex-col text-center transition-colors',
                            'bg-border/40 text-ink hover:bg-white',
                        ])
                        title="{{ $tile->name }}"
                    >
                        {{-- A tile starts a shade darker than the page and turns white on hover (the logos have transparent backgrounds). A logo with its own colour is already a rounded badge in the image, so
                             colour never fills the cell. Brands without a logo show their name as plain text on the same white. --}}
                        <span class="flex min-h-0 flex-1 items-center justify-center p-0.5">
                            @if ($tile->logoUrl !== null)
                                <img src="{{ $tile->logoUrl }}" alt="" loading="lazy" width="360" height="240" class="h-full w-full object-contain">
                            @else
                                <span aria-hidden="true" class="line-clamp-3 break-words px-2 text-sm font-semibold leading-tight">{{ $tile->name }}</span>
                            @endif
                        </span>

                        <span
                            x-show="isSelected(@js($tile->slug))"
                            x-cloak
                            aria-hidden="true"
                            class="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-brand-blue text-white"
                        >
                            <svg viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                        </span>

                        <span
                            aria-hidden="true"
                            class="absolute bottom-1 right-1 min-w-5 rounded-full bg-surface-muted px-1.5 text-center text-[0.6875rem] font-semibold leading-5 tabular-nums text-ink-muted group-hover:bg-white"
                            data-brand-count
                        >{{ $tile->productCount }}</span>

                        {{-- No visible label for logos: the name is for screen readers (and the tooltip). --}}
                        <span class="sr-only">{{ $tile->name }}, {{ $tile->productCount }} {{ Str::plural('product', $tile->productCount) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>

        <p x-show="noMatches" x-cloak class="mt-8 text-ink-muted">
            No brand matches that name.
        </p>

        <div
            x-show="selected.length > 0"
            x-cloak
            x-transition.opacity
            class="sticky bottom-0 z-10 mt-10 border-t border-border bg-white py-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="font-semibold" aria-live="polite" x-text="selected.length === 1 ? '1 brand selected' : selected.length + ' brands selected'"></p>

                <div class="flex flex-wrap gap-3">
                    <x-ui.button-outline shape="md" x-on:click="selected = []">Clear</x-ui.button-outline>
                    <x-ui.button :href="route('brands')" x-bind:href="url">View products</x-ui.button>
                </div>
            </div>
        </div>
    </div>
</x-layouts.storefront>
