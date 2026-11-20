<div>
    <x-ui.section-heading title="Your cart" tag="h1" />

    @if ($notice)
        <x-ui.alert class="mt-6" data-cart-notice>{{ $notice }}</x-ui.alert>
    @endif

    @if ($error)
        <x-ui.alert variant="error" class="mt-6" data-cart-error>{{ $error }}</x-ui.alert>
    @endif

    @if ($lines === [])
        <div class="mt-8" data-cart-empty>
            <p class="text-ink-muted">Your cart is empty.</p>
            <x-ui.button :href="route('shop')" class="mt-4">Browse products</x-ui.button>
        </div>
    @else
        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_20rem]">
            <ul class="divide-y divide-border border-y border-border" data-cart-lines>
                @foreach ($lines as $line)
                    <li wire:key="line-{{ $line['id'] }}" class="grid grid-cols-[5rem_1fr] gap-x-4 gap-y-3 py-5 sm:grid-cols-[6rem_1fr_auto]" data-cart-line>
                        <div class="row-span-2 aspect-square overflow-hidden rounded border border-border bg-surface-muted sm:row-span-1">
                            @if ($line['image'])
                                <img src="{{ $line['image'] }}" alt="" loading="lazy" width="300" height="300" class="h-full w-full object-contain">
                            @endif
                        </div>

                        <div class="min-w-0">
                            @if ($line['brand'])
                                <p class="text-xs uppercase tracking-wide text-ink-muted">{{ $line['brand'] }}</p>
                            @endif

                            <p class="font-semibold">
                                @if ($line['url'])
                                    <a href="{{ $line['url'] }}" class="hover:underline">{{ $line['name'] }}</a>
                                @else
                                    {{ $line['name'] }}
                                @endif
                            </p>

                            @if ($line['options'] !== [])
                                <p class="text-sm text-ink-muted" data-cart-options>
                                    @foreach ($line['options'] as $option)
                                        {{ $option['label'] }}: {{ $option['value'] }}@if (! $loop->last), @endif
                                    @endforeach
                                </p>
                            @endif

                            <p class="mt-1 text-sm">
                                <span class="text-ink-muted">Price</span>
                                <span class="font-semibold" data-line-unit>{{ $line['unit'] }}</span>
                                <span class="text-ink-muted">each, {{ $suffix }}</span>
                            </p>

                            @if ($line['problem'])
                                <p role="alert" class="mt-2 text-sm font-semibold text-danger" data-line-problem>{{ $line['problem'] }}</p>
                            @endif

                            @if (isset($lineErrors[$line['id']]))
                                <p id="error-line-{{ $line['id'] }}" role="alert" class="mt-2 text-sm font-semibold text-danger" data-line-error>{{ $lineErrors[$line['id']] }}</p>
                            @endif
                        </div>

                        <div class="col-start-2 flex flex-wrap items-center gap-x-6 gap-y-2 sm:col-start-3 sm:flex-col sm:items-end">
                            <div>
                                <label for="quantity-{{ $line['id'] }}" class="block text-xs text-ink-muted">Qty<span class="sr-only"> for {{ $line['name'] }}</span></label>
                                <input
                                    id="quantity-{{ $line['id'] }}"
                                    type="number"
                                    inputmode="numeric"
                                    min="1"
                                    step="1"
                                    @if ($line['max'] !== null) max="{{ $line['max'] }}" @endif
                                    wire:model.blur="quantities.{{ $line['id'] }}"
                                    @if (isset($lineErrors[$line['id']])) aria-invalid="true" aria-describedby="error-line-{{ $line['id'] }}" @endif
                                    data-line-quantity
                                    @class([
                                        'w-24 rounded border px-3 py-2 text-lg',
                                        'border-border-strong' => ! isset($lineErrors[$line['id']]),
                                        'border-danger' => isset($lineErrors[$line['id']]),
                                    ])
                                >
                            </div>

                            <p class="sm:text-right">
                                <span class="block text-xs text-ink-muted">Line total</span>
                                <span class="font-bold" data-line-total>{{ $line['total'] }}</span>
                                <span class="text-xs text-ink-muted">{{ $suffix }}</span>
                            </p>

                            <button
                                type="button"
                                wire:click="remove({{ $line['id'] }})"
                                class="text-sm font-semibold text-brand-blue underline hover:text-brand-navy"
                                aria-label="Remove {{ $line['name'] }} from your cart"
                                data-line-remove
                            >Remove</button>
                        </div>
                    </li>
                @endforeach
            </ul>

            <aside aria-labelledby="summary-heading" class="h-fit rounded border border-border bg-surface-muted p-5" data-cart-summary>
                <h2 id="summary-heading" class="text-lg font-bold">Cart summary</h2>

                {{-- The lines again, compact: price, quantity and line total ex VAT. No VAT here. --}}
                <ul class="mt-4 divide-y divide-border" data-cart-summary-lines>
                    @foreach ($lines as $line)
                        <li wire:key="cart-summary-line-{{ $line['id'] }}" class="flex gap-3 py-3 first:pt-0" data-cart-summary-line>
                            <div class="h-14 w-14 shrink-0 overflow-hidden rounded border border-border bg-white">
                                @if ($line['image'])
                                    <img src="{{ $line['image'] }}" alt="" loading="lazy" width="300" height="300" class="h-full w-full object-contain">
                                @endif
                            </div>

                            <div class="min-w-0 flex-1 text-sm">
                                <p class="font-semibold">{{ $line['name'] }}</p>
                                @if ($line['options'] !== [])
                                    <p class="text-xs text-ink-muted">
                                        @foreach ($line['options'] as $option){{ $option['label'] }}: {{ $option['value'] }}@if (! $loop->last), @endif @endforeach
                                    </p>
                                @endif
                                <p class="mt-0.5 text-ink-muted">{{ $line['unit'] }} each <span aria-hidden="true">&times;</span> Qty {{ $line['quantity'] }}</p>
                            </div>

                            <div class="shrink-0 text-right text-sm">
                                <p class="text-xs text-ink-muted">Line total</p>
                                <p class="font-bold" data-cart-summary-line-total>{{ $line['total'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <dl class="mt-4 space-y-2 text-sm" data-cart-totals>
                    <div class="flex justify-between gap-4">
                        <dt>Items</dt>
                        <dd data-cart-items>{{ $itemCount }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-border pt-2 text-lg font-bold">
                        <dt>Subtotal <span class="text-xs font-normal text-ink-muted">{{ $suffix }}</span></dt>
                        <dd data-cart-subtotal>{{ $subTotal }}</dd>
                    </div>
                </dl>

                <p class="mt-3 text-xs text-ink-muted" data-checkout-note>VAT and delivery are worked out at checkout.</p>
                <x-storefront.delivery-note class="mt-2 text-xs" />

                @if ($canCheckout)
                    <x-ui.button :href="route('checkout')" class="mt-5 w-full" data-checkout-link>Proceed to checkout</x-ui.button>
                @else
                    <x-ui.button class="mt-5 w-full" disabled data-checkout-disabled>Proceed to checkout</x-ui.button>
                    <p class="mt-2 text-sm text-ink-muted">Fix the items marked above to continue.</p>
                @endif

                <p class="mt-4 text-center text-sm"><a href="{{ route('shop') }}" class="font-semibold text-brand-blue hover:underline">Continue shopping</a></p>
            </aside>
        </div>
    @endif
</div>
