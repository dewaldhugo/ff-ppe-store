{{--
    Compare products: up to four side by side, one row per fact (App\Domain\Compare\CompareTable). The products are the session's
    (ticked on the cards), or those in a shared link (/compare?p=12,45,78). "Show differences only" hides the rows where every product
    says the same. Noindex: it is a personal page.
--}}
@php
    $shared = request()->filled('p');
@endphp

<x-layouts.storefront title="Compare products" :noindex="true">
    <x-ui.section-heading title="Compare products" tag="h1">Up to {{ \App\Domain\Compare\Compare::MAX }} products side by side. Tick Compare on a product to add it.</x-ui.section-heading>

    @if ($products->isEmpty())
        <div class="mt-8 rounded border border-border bg-surface-muted p-6" data-compare-empty>
            <p class="font-semibold">You have not picked any products to compare yet.</p>
            <p class="mt-1 text-ink-muted">Tick Compare on two or more products, then come back here.</p>
            <p class="mt-4"><x-ui.button :href="route('shop')">Browse products</x-ui.button></p>
        </div>
    @else
        <div x-data="{ diff: false }" class="mt-6" data-compare>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
                    <input type="checkbox" class="size-4 cursor-pointer rounded border-border-strong accent-brand-teal" x-model="diff" data-compare-differences>
                    <span>Show differences only</span>
                </label>

                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <label class="inline-flex items-center gap-2 text-ink-muted">
                        <span>Link to this comparison</span>
                        <input type="text" readonly value="{{ $shareUrl }}" class="w-56 rounded-md border border-border-strong bg-surface-muted px-2 py-1 text-xs" x-on:focus="$el.select()" data-compare-share>
                    </label>

                    @unless ($shared)
                        <form method="POST" action="{{ route('compare.clear') }}">
                            @csrf
                            <button type="submit" class="underline" data-compare-clear-all>Clear all</button>
                        </form>
                    @endunless
                </div>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[42rem] table-fixed border-collapse text-left text-sm" data-compare-table>
                    <caption class="sr-only">Product comparison</caption>
                    <thead>
                        <tr>
                            <td class="w-36 align-bottom"></td>
                            @foreach ($products as $product)
                                <th scope="col" class="px-3 pb-4 align-top font-normal" data-compare-product="{{ $product->id }}">
                                    <div class="card-backdrop aspect-square overflow-hidden rounded">
                                        @if ($product->thumbnail)
                                            <img src="{{ $product->thumbnail->getUrl('thumb') }}" alt="" loading="lazy" width="300" height="300" class="card-picture">
                                        @endif
                                    </div>
                                    <p class="mt-3 text-xs uppercase tracking-wide text-ink-muted">{{ $product->brand?->name }}</p>
                                    <a href="{{ route('product', $product->defaultUrl?->slug ?? $product->id) }}" class="mt-1 block font-semibold text-ink hover:underline">{{ $product->translateAttribute('name') }}</a>
                                    <p class="text-ink-muted">{{ \App\Support\PlainText::from($product->translateAttribute('title')) }}</p>

                                    @unless ($shared)
                                        <form method="POST" action="{{ route('compare.toggle', $product->id) }}" class="mt-2">
                                            @csrf
                                            <button type="submit" class="text-xs underline" data-compare-remove>Remove<span class="sr-only"> {{ $product->translateAttribute('name') }}</span></button>
                                        </form>
                                    @endunless
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-border" @if ($row['same']) x-show="! diff" data-compare-same @endif data-compare-row>
                                <th scope="row" class="py-3 pr-3 align-top font-semibold text-ink">{{ $row['label'] }}</th>
                                @foreach ($row['cells'] as $cell)
                                    <td class="px-3 py-3 align-top text-ink-muted">
                                        @if (filled($cell))
                                            {{ $cell }}
                                        @else
                                            <span aria-label="Not stated">&ndash;</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        <tr class="border-t border-border">
                            <td></td>
                            @foreach ($products as $product)
                                <td class="px-3 py-4">
                                    <x-ui.button-outline :href="route('product', $product->defaultUrl?->slug ?? $product->id)" shape="md" class="px-4 py-2 text-sm" data-view-product>View product</x-ui.button-outline>
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif
</x-layouts.storefront>
