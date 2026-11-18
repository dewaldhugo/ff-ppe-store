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

        <ul class="mt-4 flex flex-wrap gap-x-[5px] gap-y-2" data-testid="brand-grid">
            @foreach ($tiles as $tile)
                <li class="w-[50px] sm:w-[60px]" x-show="matches(@js(Str::lower($tile->name)))" data-brand="{{ $tile->slug }}">
                    <a
                        href="{{ route('brands.show', $tile->slug) }}"
                        x-on:click.prevent="toggle(@js($tile->slug))"
                        x-on:keydown.space.prevent="toggle(@js($tile->slug))"
                        x-bind:role="'button'"
                        x-bind:aria-pressed="isSelected(@js($tile->slug)).toString()"
                        class="group flex flex-col items-center text-center"
                    >
                        <span
                            @class([
                                'relative flex size-[46px] items-center justify-center rounded-full border border-border transition sm:size-[58px]',
                                'bg-white' => $tile->logoUrl !== null && $tile->background === null,
                            ])
                            x-bind:class="isSelected(@js($tile->slug)) ? 'ring-2 ring-brand-blue' : 'group-hover:border-brand-teal'"
                            @if ($tile->logoUrl !== null && $tile->background !== null)
                                style="background-color: #{{ $tile->background }}"
                            @elseif ($tile->logoUrl === null)
                                style="background-color: #{{ $tile->monogramColours()['background'] }}; color: #{{ $tile->monogramColours()['text'] }}"
                            @endif
                        >
                            @if ($tile->logoUrl !== null)
                                <img src="{{ $tile->logoUrl }}" alt="" loading="lazy" class="max-h-8 max-w-8 object-contain sm:max-h-10 sm:max-w-10">
                            @else
                                <span aria-hidden="true" class="text-sm font-semibold tracking-wide sm:text-base">{{ $tile->monogram() }}</span>
                            @endif

                            <span
                                x-show="isSelected(@js($tile->slug))"
                                x-cloak
                                aria-hidden="true"
                                class="absolute -right-0.5 -top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-brand-blue text-white"
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" class="h-2.5 w-2.5"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                            </span>
                        </span>

                        <span class="mt-1 line-clamp-2 break-words text-[10px] font-semibold leading-tight group-hover:underline sm:text-[11px]" title="{{ $tile->name }}">{{ $tile->name }}</span>
                        <span class="sr-only">{{ $tile->productCount }} {{ Str::plural('product', $tile->productCount) }}</span>
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
