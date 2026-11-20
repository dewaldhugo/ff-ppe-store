@php
    $money = fn ($price): string => (string) $price->formatted(trimTrailingZeros: false);
@endphp

<x-layouts.member title="Your orders" heading="Your orders" intro="Everything you have ordered, newest first. Prices are shown as paid, including VAT.">
    @if ($orders->isEmpty())
        <div class="rounded border border-border bg-surface-muted p-6" data-orders-empty>
            <p class="font-semibold">You have not placed an order yet.</p>
            <p class="mt-1 text-ink-muted">When you do, it will appear here with its invoice.</p>
            <p class="mt-4"><x-ui.button :href="route('shop')">Browse products</x-ui.button></p>
        </div>
    @else
        <div class="overflow-x-auto rounded border border-border">
            <table class="w-full min-w-[34rem] text-left text-sm" data-orders-table>
                <thead class="bg-surface-muted text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th scope="col" class="px-4 py-3">Order</th>
                        <th scope="col" class="px-4 py-3">Date</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3 text-right">Total (incl. VAT)</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($orders as $order)
                        <tr data-order-row>
                            <th scope="row" class="px-4 py-3 font-semibold">
                                <a href="{{ route('account.orders.show', $order->reference) }}" class="text-brand-blue underline" data-order-link>{{ $order->reference }}</a>
                                @if ($order->customer_reference)
                                    <span class="block text-xs font-normal text-ink-muted">PO {{ $order->customer_reference }}</span>
                                @endif
                            </th>
                            <td class="px-4 py-3">{{ $order->placed_at->format('j M Y') }}</td>
                            <td class="px-4 py-3" data-order-status>{{ \App\Domain\Orders\OrderStatus::label($order->status) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ $money($order->total) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('account.orders.show', $order->reference) }}" class="font-semibold text-brand-blue underline">View</a>
                                <span aria-hidden="true" class="px-1 text-ink-muted">|</span>
                                <a href="{{ route('account.invoices.show', $order->reference) }}" class="font-semibold text-brand-blue underline">Invoice</a>
                                <span aria-hidden="true" class="px-1 text-ink-muted">|</span>
                                <form method="POST" action="{{ route('account.orders.reorder', $order->reference) }}" class="inline" data-reorder-form>
                                    @csrf
                                    <button type="submit" class="font-semibold text-brand-blue underline" data-reorder>Buy again</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6" data-orders-pagination>{{ $orders->links() }}</div>
    @endif
</x-layouts.member>
