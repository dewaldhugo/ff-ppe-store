{{-- Ten random published Safety Footwear products (two rows of five on desktop), a new pick on every page load. Each card is one link. --}}
@if ($products->isNotEmpty())
    <section class="py-12 sm:py-16" aria-labelledby="home-products" data-home-products>
        <x-ui.container>
            <x-ui.section-heading id="home-products" title="Featured products" />

            <ul class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($products as $product)
                    @php
                        $price = $priceDisplay->fromPrice($product);
                        $hasRange = $product->variants->flatMap->prices->pluck('price.value')->unique()->count() > 1;
                    @endphp
                    <li class="group relative flex flex-col rounded border border-border p-3 transition-colors hover:border-brand-teal" data-home-product>
                        <div class="aspect-square overflow-hidden rounded bg-surface-muted">
                            @if ($product->thumbnail)
                                <img
                                    src="{{ $product->thumbnail->getUrl('thumb') }}"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                    width="300"
                                    height="300"
                                    class="h-full w-full object-contain"
                                >
                            @endif
                        </div>

                        <p class="mt-3 text-xs uppercase tracking-wide text-ink-muted">{{ $product->brand?->name }}</p>
                        <h3 class="font-semibold">
                            <a href="{{ route('product', $product->defaultUrl?->slug ?? $product->id) }}" class="after:absolute after:inset-0 hover:underline">
                                {{ $product->translateAttribute('name') }}
                            </a>
                        </h3>
                        <p class="text-sm text-ink-muted">{{ $product->translateAttribute('title') }}</p>

                        @if ($price)
                            <p class="mt-auto pt-2" data-price>
                                @if ($hasRange)
                                    <span class="text-sm text-ink-muted">From</span>
                                @endif
                                <span class="font-bold">{{ $price->formatted() }}</span>
                                <span class="text-xs text-ink-muted">{{ $price->suffix() }}</span>
                            </p>
                        @endif

                        <x-ui.card-cta class="mt-3" data-view-product>View product</x-ui.card-cta>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
@endif
