{{--
    A product card, as in the category listings and the related products on a product page: thumbnail, colour
    swatches, brand, name, title, price and the "View product" button. The whole card is one link
    (the name link is stretched over it). Needs `brand`, `defaultUrl`, `thumbnail`, `variants.prices` and
    `variants.values.option` loaded. Prices follow PriceDisplay, so guests see none. Renders the <li>, so place it in a list.
--}}
@props(['product'])

@php
    $price = app(\App\Domain\Pricing\PriceDisplay::class)->fromPrice($product);
    $inStock = $product->variants->sum('stock') > 0;
    $prices = $product->variants->flatMap->prices->pluck('price.value')->unique();
    $swatches = \App\Domain\Catalogue\Options\ProductSwatches::for($product);
    $safetyClass = trim((string) $product->attr('safety_class'));
@endphp

<li {{ $attributes->class('group relative flex flex-col rounded border border-border p-3 transition-colors hover:border-brand-teal') }}>
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
    {{-- The name, with the safety class beside it at 20% smaller type on the light teal of the menu bar, as on the product page. --}}
    <h3 class="flex flex-wrap items-center gap-x-2 gap-y-1 py-1.5 text-[0.9375rem] font-semibold">
        <a href="{{ route('product', $product->defaultUrl?->slug ?? $product->id) }}" class="after:absolute after:inset-0 hover:underline">
            {{ $product->translateAttribute('name') }}
        </a>
        @if (filled($safetyClass))
            <span class="rounded bg-brand-teal-light px-2 text-xs text-brand-navy" data-card-class>{{ $safetyClass }}</span>
        @endif
    </h3>
    <p class="text-sm text-ink-muted">{{ $product->translateAttribute('title') }}</p>

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
