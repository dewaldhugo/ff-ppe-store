@php
    $label = 'block text-xs font-semibold text-ink';
    $field = 'mt-1 block w-full rounded-md border border-border-strong bg-white px-3 py-2 text-sm text-ink focus:border-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/30';
@endphp

{{--
    The quick order form: each favourite on a row with its colour and size to pick, a quantity and an
    "Add to order" button. Choices follow the product page's rules (VariantSelection).
--}}
<div data-quick-order-page>
    <x-account.company-heading class="mb-6" />

    <x-ui.section-heading title="Quick order" tag="h1">Choose a colour and size for each of your favourites, then add it to your order.</x-ui.section-heading>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
        <a href="{{ route('favourites') }}" class="font-semibold text-brand-blue underline" data-quick-order-back>&larr; Your favourites</a>
        <a href="{{ route('cart') }}" class="font-semibold text-brand-blue underline" data-quick-order-cart>
            View your cart (<span data-quick-order-count x-data="{ count: {{ $cartCount }} }" x-on:cart-updated.window="count = $event.detail.count" x-text="count">{{ $cartCount }}</span>)
        </a>
    </div>

    <x-storefront.delivery-note class="mt-3" />

    @if ($rows === [])
        <div class="mt-8 rounded border border-border bg-surface-muted p-6" data-quick-order-empty>
            <p class="font-semibold">You have not saved any products yet.</p>
            <p class="mt-1 text-ink-muted">Select Add to favourites on a product to keep it here, then order it from this page.</p>
            <p class="mt-4"><x-ui.button :href="route('shop')">Browse products</x-ui.button></p>
        </div>
    @else
        <ul class="mt-6 divide-y divide-border border-y border-border" data-quick-order-rows>
            @foreach ($rows as $row)
                <li wire:key="quick-order-{{ $row['id'] }}" class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-4 gap-y-3 py-5 md:grid-cols-[5rem_minmax(0,1.3fr)_repeat(2,minmax(0,1fr))_5rem_auto] md:items-end" data-quick-order-row data-product-id="{{ $row['id'] }}">
                    <div class="row-span-1 aspect-square overflow-hidden rounded border border-border bg-surface-muted md:self-center">
                        @if ($row['image'])
                            <img src="{{ $row['image'] }}" alt="" loading="lazy" width="300" height="300" class="h-full w-full object-contain">
                        @endif
                    </div>

                    <div class="min-w-0 md:self-center">
                        @if ($row['brand'])
                            <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $row['brand'] }}</p>
                        @endif
                        <h2 class="font-semibold"><a href="{{ $row['url'] }}" class="hover:underline">{{ $row['name'] }}</a></h2>
                        @if ($row['title'])
                            <p class="text-sm text-ink-muted">{{ $row['title'] }}</p>
                        @endif
                        @if ($row['price'])
                            <p class="mt-1 text-sm" data-row-price>
                                @unless ($row['exact'])<span class="text-ink-muted">From</span>@endunless
                                <span class="font-bold">{{ $row['price']->formatted() }}</span>
                                <span class="text-xs text-ink-muted">{{ $row['price']->suffix() }}</span>
                            </p>
                        @endif
                    </div>

                    @foreach ($row['options'] as $option)
                        <div class="col-span-2 md:col-span-1">
                            <label for="qo-{{ $row['id'] }}-{{ $option['handle'] }}" class="{{ $label }}">{{ Str::ucfirst($option['prompt']) }}</label>
                            <select
                                id="qo-{{ $row['id'] }}-{{ $option['handle'] }}"
                                wire:model.live="choices.{{ $row['id'] }}.{{ $option['handle'] }}"
                                class="{{ $field }}"
                                data-option="{{ $option['handle'] }}"
                            >
                                <option value="" @selected($option['selected'] === null)>Select {{ $option['prompt'] }}</option>
                                @foreach ($option['values'] as $value)
                                    <option value="{{ $value['key'] }}" @selected($value['selected']) @disabled(! $value['available'])>
                                        {{ $value['name'] }}@if ($option['handle'] === 'shoe_size' && isset($row['eu'][$value['name']])) (EU {{ $row['eu'][$value['name']] }})@endif @unless ($value['available']) - out of stock @endunless
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach

                    @if ($row['options'] === [])
                        {{-- A product with no options: nothing to choose, so keep the columns lined up. --}}
                        <div class="hidden md:col-span-2 md:block"></div>
                    @elseif (count($row['options']) === 1)
                        <div class="hidden md:block"></div>
                    @endif

                    <div class="col-span-1 md:col-span-1">
                        <label for="qo-{{ $row['id'] }}-quantity" class="{{ $label }}">Quantity</label>
                        <input
                            id="qo-{{ $row['id'] }}-quantity"
                            type="text"
                            inputmode="numeric"
                            wire:model="quantities.{{ $row['id'] }}"
                            wire:keydown.enter="add({{ $row['id'] }})"
                            maxlength="6"
                            class="{{ $field }}"
                            data-quantity
                        >
                    </div>

                    <div class="col-span-1 md:col-span-1">
                        <x-ui.button type="button" class="w-full px-4! py-2! text-sm" wire:click="add({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="add({{ $row['id'] }})" data-add-to-order>Add to order</x-ui.button>
                    </div>

                    @if (isset($messages[$row['id']]))
                        <p
                            role="{{ $messages[$row['id']]['type'] === 'error' ? 'alert' : 'status' }}"
                            @class([
                                'col-span-2 text-sm md:col-start-2 md:col-span-5',
                                'text-danger' => $messages[$row['id']]['type'] === 'error',
                                'text-success' => $messages[$row['id']]['type'] === 'success',
                            ])
                            data-row-message="{{ $messages[$row['id']]['type'] }}"
                        >
                            {{ $messages[$row['id']]['text'] }}
                            @if ($messages[$row['id']]['type'] === 'success')
                                <a href="{{ route('cart') }}" class="font-semibold underline">View cart</a>
                            @endif
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
