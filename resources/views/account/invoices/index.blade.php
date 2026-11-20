@php
    $money = fn ($price): string => (string) $price->formatted(trimTrailingZeros: false);
@endphp

<x-layouts.member title="Your invoices" heading="Your invoices" intro="One VAT invoice for each order. Open one to print it or save it as a PDF.">
    @if ($orders->isEmpty())
        <div class="rounded border border-border bg-surface-muted p-6" data-invoices-empty>
            <p class="font-semibold">You have no invoices yet.</p>
            <p class="mt-1 text-ink-muted">An invoice is made for every order you place.</p>
            <p class="mt-4"><x-ui.button :href="route('shop')">Browse products</x-ui.button></p>
        </div>
    @else
        <div class="overflow-x-auto rounded border border-border">
            <table class="w-full min-w-[34rem] text-left text-sm" data-invoices-table>
                <thead class="bg-surface-muted text-xs uppercase tracking-wide text-ink-muted">
                    <tr>
                        <th scope="col" class="px-4 py-3">Invoice</th>
                        <th scope="col" class="px-4 py-3">Date</th>
                        <th scope="col" class="px-4 py-3">Payment</th>
                        <th scope="col" class="px-4 py-3 text-right">Total (incl. VAT)</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($orders as $order)
                        @php
                            $invoice = new \App\Domain\Orders\Invoice($order);
                        @endphp
                        <tr data-invoice-row>
                            <th scope="row" class="px-4 py-3 font-semibold">
                                <a href="{{ route('account.invoices.show', $order->reference) }}" class="text-brand-blue underline" data-invoice-link>{{ $invoice->number() }}</a>
                            </th>
                            <td class="px-4 py-3">{{ $invoice->date()->format('j M Y') }}</td>
                            <td @class(['px-4 py-3', 'font-semibold text-danger' => $invoice->isOverdue()]) data-invoice-payment>{{ $invoice->paymentStatus() }}</td>
                            <td class="px-4 py-3 text-right font-semibold">{{ $money($order->total) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('account.invoices.show', $order->reference) }}" class="font-semibold text-brand-blue underline">View</a>
                                <span aria-hidden="true" class="px-1 text-ink-muted">|</span>
                                <a href="{{ route('account.orders.show', $order->reference) }}" class="font-semibold text-brand-blue underline">Order</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6" data-invoices-pagination>{{ $orders->links() }}</div>
    @endif
</x-layouts.member>
