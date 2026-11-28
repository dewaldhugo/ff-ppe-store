@props(['exclude' => null, 'limit' => 6])

{{--
    "Recently viewed": the products this visitor has opened lately (App\Domain\Catalogue\Recent\RecentlyViewed, in the session),
    newest first, on the same card as the listings. Nothing is drawn while there are none, so a first visit shows no empty heading.
    On the product page the product itself is left out.
--}}
@php
    $recent = app(\App\Domain\Catalogue\Recent\RecentlyViewed::class)->products($exclude === null ? null : (int) $exclude, (int) $limit);
@endphp

@if ($recent->isNotEmpty())
    <section aria-labelledby="recently-viewed-heading" {{ $attributes->class('mt-10') }} data-recently-viewed>
        <h2 id="recently-viewed-heading" class="text-xl font-bold">Recently viewed</h2>
        <ul class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3 2xl:grid-cols-6">
            @foreach ($recent as $product)
                <x-storefront.product-card :product="$product" data-recent-product="{{ $product->id }}" />
            @endforeach
        </ul>
    </section>
@endif
