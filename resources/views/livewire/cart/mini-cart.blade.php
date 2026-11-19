<div class="w-96 max-w-[calc(100vw-2rem)] rounded-md border border-border bg-white p-4 shadow-lg" data-mini-cart>
    @if (! $loaded)
        <p class="text-sm text-ink-muted" data-mini-cart-loading>Loading your cart...</p>
    @elseif ($lines === [])
        <p class="text-ink-muted" data-mini-cart-empty>Your cart is empty.</p>
    @else
        <ul class="divide-y divide-border" data-mini-cart-lines>
            @foreach ($lines as $line)
                <li wire:key="mini-line-{{ $line['id'] }}" class="flex gap-3 py-3 first:pt-0" data-mini-cart-line>
                    <div class="h-14 w-14 shrink-0 overflow-hidden rounded border border-border bg-surface-muted">
                        @if ($line['image'])
                            <img src="{{ $line['image'] }}" alt="" loading="lazy" width="300" height="300" class="h-full w-full object-contain">
                        @endif
                    </div>

                    <div class="min-w-0 flex-1 text-sm">
                        <p class="truncate font-semibold">
                            @if ($line['url'])
                                <a href="{{ $line['url'] }}" class="hover:underline">{{ $line['name'] }}</a>
                            @else
                                {{ $line['name'] }}
                            @endif
                        </p>

                        @if ($line['options'] !== [])
                            <p class="truncate text-xs text-ink-muted">
                                @foreach ($line['options'] as $option)
                                    {{ $option['label'] }}: {{ $option['value'] }}@if (! $loop->last), @endif
                                @endforeach
                            </p>
                        @endif

                        <p class="mt-0.5 text-ink-muted">{{ $line['quantity'] }} x {{ $line['unit'] }}</p>

                        @if ($line['problem'])
                            <p class="text-xs font-semibold text-danger" data-mini-cart-problem>Needs attention in your cart</p>
                        @endif
                    </div>

                    <p class="shrink-0 text-sm font-bold">{{ $line['total'] }}</p>
                </li>
            @endforeach
        </ul>

        @if ($more > 0)
            <p class="border-t border-border pt-3 text-sm text-ink-muted" data-mini-cart-more>and {{ $more }} more {{ Str::plural('item', $more) }} in your cart</p>
        @endif

        <div class="mt-3 flex items-baseline justify-between border-t border-border pt-3">
            <span class="font-semibold">Subtotal <span class="text-xs font-normal text-ink-muted">({{ $itemCount }} {{ Str::plural('item', $itemCount) }})</span></span>
            <span class="text-lg font-bold" data-mini-cart-subtotal>{{ $subTotal }} <span class="text-xs font-normal text-ink-muted">{{ $suffix }}</span></span>
        </div>
        <p class="mt-1 text-xs text-ink-muted">VAT and delivery are worked out at checkout.</p>

        <div class="mt-4 grid grid-cols-2 gap-2">
            <x-ui.button-outline :href="route('cart')" shape="md" class="px-3" data-mini-cart-view>View cart</x-ui.button-outline>

            @if ($canCheckout)
                <x-ui.button :href="route('checkout')" class="px-3" data-mini-cart-checkout>Checkout</x-ui.button>
            @else
                <x-ui.button class="px-3" disabled data-mini-cart-checkout-disabled>Checkout</x-ui.button>
            @endif
        </div>
    @endif
</div>
