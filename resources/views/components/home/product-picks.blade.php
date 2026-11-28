{{-- Ten published products, five random ones from Safety Footwear and five from PPE & Gear, shown in random order, a new pick on every page load. At the largest breakpoint (2xl) all ten show, two rows of five; below it the last two are hidden, so it is two rows of four on desktop and four of two on mobile, and no row is ever a single product. Each card is one link. --}}
@if ($products->isNotEmpty())
    <section class="py-12 sm:py-16" aria-labelledby="home-products" data-home-products>
        <x-ui.container>
            <x-ui.section-heading id="home-products" title="Featured products" />

            <ul class="mt-8 grid grid-cols-2 gap-4 md:grid-cols-4 2xl:grid-cols-5">
                @foreach ($products as $product)
                    @php
                        $price = $priceDisplay->fromPrice($product);
                        $hasRange = $product->variants->flatMap->prices->pluck('price.value')->unique()->count() > 1;
                        $safetyClass = trim((string) $product->attr('safety_class'));
                    @endphp
                    <li @class(['group relative flex flex-col rounded border border-border p-3 transition-colors hover:border-brand-teal', 'max-2xl:hidden' => $loop->index >= 8]) data-home-product>
                        <div class="card-backdrop aspect-square overflow-hidden rounded">
                            @if ($product->thumbnail)
                                <img
                                    src="{{ $product->thumbnail->getUrl('thumb') }}"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                    width="300"
                                    height="300"
                                    class="card-picture"
                                >
                            @endif

                            {{-- The safety class, top right of the picture, as on the listing cards. --}}
                            @if (filled($safetyClass))
                                <span class="absolute right-2 top-2 rounded bg-white px-2 py-1 text-xs font-semibold text-brand-navy" data-card-class>{{ $safetyClass }}</span>
                            @endif
                        </div>

                        <p class="mt-3 text-xs uppercase tracking-wide text-ink-muted">{{ $product->brand?->name }}</p>
                        <h3 class="font-semibold">
                            <a href="{{ route('product', $product->defaultUrl?->slug ?? $product->id) }}" class="after:absolute after:inset-0 hover:underline">
                                {{ $product->translateAttribute('name') }}
                            </a>
                        </h3>
                        <p class="text-sm text-ink-muted">{{ $product->translateAttribute('title') }}</p>

                        {{-- Pinned to the bottom of the card, so every card in a row is the same height and the buttons line up, with or without a price. --}}
                        <div class="mt-auto flex flex-col">
                            @if ($price)
                                <p class="pt-2" data-price>
                                    @if ($hasRange)
                                        <span class="text-sm text-ink-muted">From</span>
                                    @endif
                                    <span class="font-bold">{{ $price->formatted() }}</span>
                                    <span class="text-xs text-ink-muted">{{ $price->suffix() }}</span>
                                </p>
                            @endif

                            <x-storefront.compare-toggle :product="$product" class="mt-3" />

                            <x-ui.card-cta class="mt-2" data-view-product>View product</x-ui.card-cta>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
@endif
