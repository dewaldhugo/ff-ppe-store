@php
    $invoice = new \App\Domain\Orders\Invoice($order);
    $status = \App\Domain\Orders\OrderStatus::label($order->status);
@endphp

<x-layouts.member :title="'Order '.$order->reference" :heading="'Order '.$order->reference">
    <p class="-mt-4 mb-6 text-sm"><a href="{{ route('account.orders') }}" class="font-semibold text-brand-blue underline">&larr; All orders</a></p>

    <div class="rounded border border-border bg-white p-6" data-order-page>
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-ink-muted">Placed</dt><dd class="font-semibold" data-order-placed>{{ $order->placed_at->format('j F Y, H:i') }}</dd></div>
            <div><dt class="text-ink-muted">Status</dt><dd class="font-semibold" data-order-status>{{ $status }}</dd></div>
            <div><dt class="text-ink-muted">Payment</dt><dd class="font-semibold">{{ $invoice->paymentMethod() }}: {{ $invoice->paymentStatus() }}</dd></div>
            @if ($order->customer_reference)
                <div><dt class="text-ink-muted">Your PO number</dt><dd class="font-semibold">{{ $order->customer_reference }}</dd></div>
            @endif
        </dl>

        @if ($order->notes)
            <p class="mt-4 text-sm"><span class="text-ink-muted">Order notes:</span> {{ $order->notes }}</p>
        @endif

        <x-orders.detail :order="$order" />

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('account.orders.reorder', $order->reference) }}" data-reorder-form>
                @csrf
                <x-ui.button type="submit" data-reorder>Buy again</x-ui.button>
            </form>
            <x-ui.button-outline :href="route('account.invoices.show', $order->reference)" data-view-invoice>View invoice</x-ui.button-outline>
            <x-ui.button-outline :href="route('account.orders')">All orders</x-ui.button-outline>
        </div>
        <p class="mt-2 text-xs text-ink-muted">Buy again puts these items in your cart at today's prices. Delivery and VAT are worked out at checkout.</p>
    </div>
</x-layouts.member>
