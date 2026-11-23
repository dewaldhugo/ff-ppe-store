@props(['order'])

{{--
    An order's lines with their VAT, the totals and the delivery address. Shared by the confirmation page
    after checkout and the order page in My Account, so they always agree. Money is Lunar's.
--}}
@php
    $money = fn ($price): string => (string) $price->formatted(trimTrailingZeros: false);
    $rate = fn ($line): string => rtrim(rtrim(number_format((float) $line->tax_breakdown->amounts->sum('percentage'), 2), '0'), '.');
    $goods = $order->lines->where('type', 'physical');
    $delivery = $order->lines->where('type', 'shipping');
    $suffix = \App\Domain\Pricing\DisplayPrice::SUFFIX;
@endphp

<ul class="mt-6 divide-y divide-border border-y border-border" data-order-lines>
    @foreach ($goods as $line)
        <li class="flex justify-between gap-4 py-3 text-sm" data-order-line>
            <div>
                <p class="font-semibold">{{ $line->description }}</p>
                {{-- Colour, UK size, EU size, as placed (App\Domain\Orders\LineDetails). --}}
                @php($details = \App\Domain\Orders\LineDetails::text($line))
                <p class="text-xs text-ink-muted">Code {{ $line->identifier }}@if ($details !== ''), {{ $details }}@endif</p>
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
