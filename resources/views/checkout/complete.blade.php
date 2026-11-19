@php
    $money = fn ($price): string => (string) $price->formatted(trimTrailingZeros: false);
    $rate = fn ($line): string => rtrim(rtrim(number_format((float) $line->tax_breakdown->amounts->sum('percentage'), 2), '0'), '.');
    $goods = $order->lines->where('type', 'physical');
    $delivery = $order->lines->where('type', 'shipping');
    $suffix = \App\Domain\Pricing\DisplayPrice::SUFFIX;
    $contactEmail = $order->meta['contact_email'] ?? null;
@endphp

<x-layouts.checkout title="Order placed">
    <div class="mx-auto max-w-3xl rounded border border-border bg-white p-6" data-order-complete>
        <h1 class="text-2xl font-bold sm:text-3xl">Thank you, your order is placed</h1>
        <p class="mt-2 text-ink-muted">
            Order reference <strong class="text-ink" data-order-reference>{{ $order->reference }}</strong>.
            @if ($contactEmail)
                We will contact you at {{ $contactEmail }} with our bank details for the transfer. Your goods are dispatched once payment is received.
            @else
                We will contact you with our bank details for the transfer. Your goods are dispatched once payment is received.
            @endif
        </p>

        @if ($order->customer_reference)
            <p class="mt-2 text-sm">Your PO number: <strong>{{ $order->customer_reference }}</strong></p>
        @endif

        <ul class="mt-6 divide-y divide-border border-y border-border" data-order-lines>
            @foreach ($goods as $line)
                <li class="flex justify-between gap-4 py-3 text-sm" data-order-line>
                    <div>
                        <p class="font-semibold">{{ $line->description }}</p>
                        <p class="text-xs text-ink-muted">Code {{ $line->identifier }}@if ($line->option), {{ $line->option }}@endif</p>
                        <p class="text-ink-muted">Qty {{ $line->quantity }}</p>
                        @if ((float) $line->tax_breakdown->amounts->sum('percentage') === 0.0)
                            <p class="mt-1"><span class="rounded border border-success bg-success-surface px-2 py-0.5 text-xs font-bold text-success" data-zero-rated>Zero-rated: 0% VAT</span></p>
                        @else
                            <p class="text-xs text-ink-muted">VAT {{ $rate($line) }}%: <span class="font-semibold text-ink">{{ $money($line->tax_total) }}</span></p>
                        @endif
                    </div>
                    <p class="shrink-0 font-bold">{{ $money($line->sub_total) }} <span class="text-xs font-normal text-ink-muted">{{ $suffix }}</span></p>
                </li>
            @endforeach
        </ul>

        <dl class="ml-auto mt-4 max-w-xs space-y-2 text-sm" data-order-totals>
            <div class="flex justify-between gap-4"><dt>Subtotal (excl. VAT)</dt><dd class="font-semibold">{{ $money($order->sub_total) }}</dd></div>
            @foreach ($delivery as $line)
                <div class="flex justify-between gap-4"><dt>{{ $line->description }} (excl. VAT)</dt><dd class="font-semibold">{{ $line->sub_total->value === 0 ? 'Free' : $money($line->sub_total) }}</dd></div>
            @endforeach
            <div class="flex justify-between gap-4"><dt>VAT</dt><dd class="font-semibold">{{ $money($order->tax_total) }}</dd></div>
            <div class="flex justify-between gap-4 border-t border-border pt-2 text-lg font-bold"><dt>Total (incl. VAT)</dt><dd>{{ $money($order->total) }}</dd></div>
        </dl>

        @if ($order->shippingAddress)
            <div class="mt-6 text-sm" data-order-address>
                <h2 class="font-bold">Delivering to</h2>
                <p class="text-ink-muted">
                    {{ $order->shippingAddress->company_name }}<br>
                    {{ $order->shippingAddress->line_one }}@if ($order->shippingAddress->line_two), {{ $order->shippingAddress->line_two }}@endif<br>
                    {{ $order->shippingAddress->city }}, {{ $order->shippingAddress->postcode }}
                </p>
            </div>
        @endif

        <p class="mt-6"><x-ui.button :href="route('shop')">Continue shopping</x-ui.button></p>
    </div>
</x-layouts.checkout>
