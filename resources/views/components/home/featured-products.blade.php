{{--
    The Product Spotlight: one campaign, picked at random on each page load (App\Domain\Catalogue\Home\HomeSpotlight,
    config/homepage.php). The campaign image sits beside the top of the product page, as it is there: the brand, the
    name, the title, the short description, the price (signed-in customers only, like every price), whether it is in
    stock, the colour and size options, and two buttons: Order now (a guest sees Log in to order) and View product.
    It is all read from the catalogue, so nothing is written twice. The colours are swatches that open the product
    page on that colour; sizes are shown, as to a guest on the product page, and are chosen there. Not shown when
    no campaign has a published product. Images are served from public/images/home.
--}}
@if ($pick)
    @php
        $spotlight = $pick['spotlight'];
        $product = $pick['product'];
        $name = (string) $product->translateAttribute('name');
        $title = (string) $product->translateAttribute('title');
        $short = (string) $product->translateAttribute('short_description');
        $price = $priceDisplay->fromPrice($product);
        $hasRange = $product->variants->flatMap->prices->pluck('price.value')->unique()->count() > 1;
        $url = route('product', $product->defaultUrl?->slug ?? $spotlight['slug']);
        $selectedColour = collect($options)->firstWhere('handle', 'colour')['selected'] ?? null;
        $orderUrl = $url.($selectedColour !== null ? '?colour='.$selectedColour : '');
        $signedIn = $priceDisplay->visible();
    @endphp
    <section class="py-12 sm:py-16" aria-labelledby="home-featured" data-home-featured>
        <x-ui.container>
            <x-ui.section-heading id="home-featured" title="Product Spotlight" />

            <div class="mt-8 grid items-center gap-8 rounded-lg border border-border p-5 sm:p-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-12" data-spotlight>
                <figure class="mx-auto w-full max-w-sm lg:max-w-none">
                    <picture>
                        @if ($spotlight['webp'])
                            <source type="image/webp" srcset="{{ asset('images/home/'.$spotlight['file'].'.webp') }}">
                        @endif
                        <img
                            src="{{ asset('images/home/'.$spotlight['file'].'.jpg') }}"
                            alt="{{ $spotlight['alt'] }}"
                            width="{{ $spotlight['width'] }}"
                            height="{{ $spotlight['height'] }}"
                            loading="lazy"
                            decoding="async"
                            class="h-auto w-full rounded-lg"
                            data-featured-image
                        >
                    </picture>
                </figure>

                <div>
                    @if ($product->brand?->name)
                        <p class="text-sm uppercase tracking-wide text-ink-muted" data-spotlight-brand>{{ $product->brand->name }}</p>
                    @endif

                    <h3 class="text-3xl font-bold tracking-tight text-ink" data-spotlight-name>{{ $name }}</h3>

                    @if (filled($title))
                        <p class="text-lg text-ink-muted" data-spotlight-title>{{ $title }}</p>
                    @endif

                    @if (filled($short))
                        <p class="mt-3 text-ink-muted" data-spotlight-short>{{ $short }}</p>
                    @endif

                    @if ($price)
                        <p class="mt-4" data-price>
                            @if ($hasRange)
                                <span class="text-ink-muted">From</span>
                            @endif
                            <span class="text-2xl font-bold">{{ $price->formatted() }}</span>
                            <span class="text-sm text-ink-muted">{{ $price->suffix() }}</span>
                        </p>
                    @endif

                    <p class="mt-2 text-sm font-semibold" data-spotlight-stock>{{ $inStock ? 'In stock' : 'Out of stock' }}</p>

                    <div class="mt-5 space-y-5" data-spotlight-options>
                        @foreach ($options as $option)
                            @if ($option['fixed'])
                                {{-- A single value needs no picker. --}}
                                <p data-option="{{ $option['handle'] }}" data-fixed>
                                    <span class="font-semibold">{{ $option['name'] }}:</span>
                                    {{ $option['values'][0]['name'] }}
                                    @if (str_ends_with($option['handle'], '_size'))
                                        <a href="{{ route('size-guide') }}" class="ml-3 inline-flex items-center rounded border border-border bg-surface-muted px-2.5 py-1 align-middle text-xs font-semibold text-ink-muted hover:bg-border">Size guide</a>
                                    @endif
                                </p>
                            @else
                                <div data-option="{{ $option['handle'] }}">
                                    <p class="font-semibold">
                                        {{ $option['name'] }}@if ($option['selected'] !== null): <span class="font-normal">{{ collect($option['values'])->firstWhere('key', $option['selected'])['name'] ?? '' }}</span>@endif
                                        @if (str_ends_with($option['handle'], '_size'))
                                            <a href="{{ route('size-guide') }}" class="ml-3 inline-flex items-center rounded border border-border bg-surface-muted px-2.5 py-1 align-middle text-xs font-semibold text-ink-muted hover:bg-border">Size guide</a>
                                        @endif
                                    </p>

                                    <ul class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($option['values'] as $value)
                                            @php
                                                $isColour = $option['handle'] === 'colour';
                                                $available = $value['available'];
                                                $chip = 'flex min-w-12 items-center gap-2 rounded border px-3 py-2 text-sm';
                                            @endphp
                                            <li data-value="{{ $value['key'] }}">
                                                @if ($isColour && $available)
                                                    <a
                                                        href="{{ $url }}?colour={{ $value['key'] }}"
                                                        @class([
                                                            $chip,
                                                            'border-brand-blue bg-brand-blue text-white' => $value['selected'],
                                                            'border-border-strong bg-white hover:border-brand-blue' => ! $value['selected'],
                                                        ])
                                                        @if ($value['selected']) aria-current="true" @endif
                                                        data-spotlight-colour
                                                    >
                                                        <x-ui.swatch :hexes="\App\Domain\Catalogue\Options\ColourSwatch::hexes($value['name'], $value['meta']['hex'] ?? null)" aria-hidden="true" class="h-4 w-4" />
                                                        <span>{{ $value['name'] }}</span>
                                                    </a>
                                                @else
                                                    <span
                                                        @class([
                                                            $chip,
                                                            'cursor-default border-border-strong bg-white' => $available,
                                                            'cursor-not-allowed border-border bg-surface-muted text-ink-muted line-through' => ! $available,
                                                        ])
                                                        @if (! $available) title="Out of stock" @endif
                                                    >
                                                        @if ($isColour)
                                                            <x-ui.swatch :hexes="\App\Domain\Catalogue\Options\ColourSwatch::hexes($value['name'], $value['meta']['hex'] ?? null)" aria-hidden="true" class="h-4 w-4" />
                                                        @endif
                                                        <span>{{ $value['name'] }}</span>
                                                        @if (! $available)
                                                            <span class="sr-only">(out of stock)</span>
                                                        @endif
                                                    </span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    {{-- Ordering is for signed-in customers: they go to the product page on the colour shown, to choose a
                         size and a quantity. A guest is sent to log in. Everyone can open the product. --}}
                    <div class="mt-6 flex flex-wrap items-center gap-3" data-spotlight-actions>
                        @if ($signedIn)
                            <x-ui.button :href="$orderUrl" data-spotlight-order>Order now</x-ui.button>
                        @else
                            <x-ui.button :href="route('login')" data-spotlight-order>Log in to order</x-ui.button>
                        @endif

                        <x-ui.button-outline :href="$url" shape="md" data-spotlight-link>View {{ $name }}</x-ui.button-outline>
                    </div>
                </div>
            </div>
        </x-ui.container>
    </section>
@endif
