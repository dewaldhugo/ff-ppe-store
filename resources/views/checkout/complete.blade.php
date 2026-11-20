@php
    $contactEmail = $order->meta['contact_email'] ?? null;
    $paidByCard = ($order->meta['payment_method'] ?? null) === \App\Domain\Checkout\PaymentMethods::CARD;
@endphp

<x-layouts.checkout title="Order placed">
    <div class="mx-auto max-w-3xl rounded border border-border bg-white p-6" data-order-complete>
        <h1 class="text-2xl font-bold sm:text-3xl">Thank you, your order is placed</h1>
        <p class="mt-2 text-ink-muted">
            Order reference <strong class="text-ink" data-order-reference>{{ $order->reference }}</strong>.
            @if ($paidByCard)
                Your card payment was successful and your order is being prepared.
            @elseif ($contactEmail)
                It is on your 30 day account: we will invoice you at {{ $contactEmail }}, and payment is due within 30 days.
            @else
                It is on your 30 day account: we will invoice you, and payment is due within 30 days.
            @endif
        </p>

        @if ($order->customer_reference)
            <p class="mt-2 text-sm">Your PO number: <strong>{{ $order->customer_reference }}</strong></p>
        @endif

        <x-orders.detail :order="$order" />

        <p class="mt-6 flex flex-wrap gap-3">
            <x-ui.button :href="route('shop')">Continue shopping</x-ui.button>
            <x-ui.button-outline :href="route('account.invoices.show', $order->reference)" data-view-invoice>View invoice</x-ui.button-outline>
            <x-ui.button-outline :href="route('account.orders')">Your orders</x-ui.button-outline>
        </p>
    </div>
</x-layouts.checkout>
