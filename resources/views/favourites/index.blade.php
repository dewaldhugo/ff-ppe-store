@php
    $priceDisplay = app(\App\Domain\Pricing\PriceDisplay::class);
@endphp

<x-layouts.storefront title="Favourites" :noindex="true">
    <x-ui.section-heading title="Your favourites" tag="h1">Products you have saved. Use the heart on a product to add or remove it.</x-ui.section-heading>

    @if ($products->isEmpty())
        <div class="mt-8 rounded border border-border bg-surface-muted p-6" data-favourites-empty>
            <p class="font-semibold">You have not saved any products yet.</p>
            <p class="mt-1 text-ink-muted">Select the heart on a product to keep it here for later.</p>
            <p class="mt-4"><x-ui.button :href="route('shop')">Browse products</x-ui.button></p>
        </div>
    @else
        <ul class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5" data-favourites-list>
            @foreach ($products as $product)
                @php
                    $price = $priceDisplay->fromPrice($product);
                    $hasRange = $product->variants->flatMap->prices->pluck('price.value')->unique()->count() > 1;
                @endphp
                <li class="group relative flex flex-col rounded border border-border p-3 transition-colors hover:border-brand-teal" data-favourite-product>
                    <div class="aspect-square overflow-hidden rounded bg-surface-muted">
                        @if ($product->thumbnail)
                            <img src="{{ $product->thumbnail->getUrl('thumb') }}" alt="" loading="lazy" decoding="async" width="300" height="300" class="h-full w-full object-contain">
                        @endif
                    </div>

                    <p class="mt-3 text-xs uppercase tracking-wide text-ink-muted">{{ $product->brand?->name }}</p>
                    <h2 class="font-semibold">
                        <a href="{{ route('product', $product->defaultUrl?->slug ?? $product->id) }}" class="after:absolute after:inset-0 hover:underline">
                            {{ $product->translateAttribute('name') }}
                        </a>
                    </h2>
                    <p class="text-sm text-ink-muted">{{ $product->translateAttribute('title') }}</p>

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

                        <x-ui.card-cta class="mt-3">View product</x-ui.card-cta>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.storefront>
