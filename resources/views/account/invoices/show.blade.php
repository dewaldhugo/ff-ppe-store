@php
    $money = fn ($price): string => (string) $price->formatted(trimTrailingZeros: false);
    $rate = fn ($line): string => rtrim(rtrim(number_format((float) $line->tax_breakdown->amounts->sum('percentage'), 2), '0'), '.');
    $goods = $order->lines->where('type', 'physical');
    $delivery = $order->lines->where('type', 'shipping');
    $billing = $order->billingAddress;
    $shipping = $order->shippingAddress;
    $address = fn ($a): array => array_filter([$a?->company_name, trim($a?->first_name.' '.$a?->last_name), $a?->line_one, $a?->line_two, $a?->city, $a?->state, $a?->postcode]);
@endphp

{{--
    A VAT invoice for one placed order, laid out to print (the browser's Print / Save as PDF). The buttons and the
    site header are left out of the print. The supplier's company and VAT numbers come from config/store.php.
--}}
<x-layouts.checkout :title="'Invoice '.$invoice->number()">
    <div class="mx-auto max-w-4xl" data-invoice>
        <p class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a href="{{ route('account.invoices') }}" class="text-sm font-semibold text-brand-blue underline">&larr; All invoices</a>
            <button type="button" class="rounded-md bg-brand-blue px-5 py-2 text-sm font-semibold text-white hover:bg-brand-navy" x-data x-on:click="window.print()" data-print-invoice>Print or save as PDF</button>
        </p>

        <article class="rounded border border-border bg-white p-6 sm:p-10 print:border-0 print:p-0">
            <header class="flex flex-wrap items-start justify-between gap-6">
                <div>
                    <h1 class="text-3xl font-bold">VAT invoice</h1>
                    <p class="mt-1 text-sm text-ink-muted">Invoice number <strong class="text-ink" data-invoice-number>{{ $invoice->number() }}</strong></p>
                </div>
                <div class="text-right text-sm">
                    <p class="font-bold">{{ \App\Support\Store::legalName() }}</p>
                    <p class="text-ink-muted">{{ \App\Support\Store::address() }}</p>
                    <p class="text-ink-muted">Company number {{ \App\Support\Store::companyNumber() }}</p>
                    <p class="text-ink-muted" data-supplier-vat>VAT number {{ \App\Support\Store::vatNumber() }}</p>
                </div>
            </header>

            <dl class="mt-8 grid gap-4 text-sm sm:grid-cols-4">
                <div><dt class="text-ink-muted">Invoice date</dt><dd class="font-semibold" data-invoice-date>{{ $invoice->date()->format('j F Y') }}</dd></div>
                <div><dt class="text-ink-muted">Order</dt><dd class="font-semibold">{{ $order->reference }}</dd></div>
                <div><dt class="text-ink-muted">Payment</dt><dd class="font-semibold">{{ $invoice->paymentMethod() }}</dd></div>
                <div><dt class="text-ink-muted">{{ $invoice->isPaid() ? 'Status' : 'Due date' }}</dt><dd class="font-semibold" data-invoice-status>{{ $invoice->isPaid() ? 'Paid' : $invoice->dueDate()->format('j F Y') }}</dd></div>
                @if ($order->customer_reference)
                    <div><dt class="text-ink-muted">Your PO number</dt><dd class="font-semibold">{{ $order->customer_reference }}</dd></div>
                @endif
            </dl>

            <div class="mt-8 grid gap-6 text-sm sm:grid-cols-2">
                <div data-invoice-billing>
                    <h2 class="font-bold">Invoice to</h2>
                    <p class="text-ink-muted">
                        @foreach ($address($billing) as $line){{ $line }}<br>@endforeach
                        @if ($billing?->tax_identifier)Customer VAT number {{ $billing->tax_identifier }}@endif
                    </p>
                </div>
                <div data-invoice-delivery>
                    <h2 class="font-bold">Delivered to</h2>
                    <p class="text-ink-muted">@foreach ($address($shipping) as $line){{ $line }}<br>@endforeach</p>
                </div>
            </div>

            <div class="mt-8 overflow-x-auto">
                <table class="w-full min-w-[36rem] text-left text-sm" data-invoice-lines>
                    <thead class="border-y border-border text-xs uppercase tracking-wide text-ink-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-3">Description</th>
                            <th scope="col" class="px-3 py-2 text-right">Qty</th>
                            <th scope="col" class="px-3 py-2 text-right">Unit price</th>
                            <th scope="col" class="px-3 py-2 text-right">Net</th>
                            <th scope="col" class="px-3 py-2 text-right">VAT</th>
                            <th scope="col" class="py-2 pl-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($goods->concat($delivery) as $line)
                            <tr data-invoice-line>
                                <td class="py-3 pr-3">
                                    <span class="font-semibold">{{ $line->description }}</span>
                                    @if ($line->type === 'physical')
                                        <span class="block text-xs text-ink-muted">Code {{ $line->identifier }}@if ($line->option), {{ $line->option }}@endif</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right">{{ $line->quantity }}</td>
                                <td class="px-3 py-3 text-right">{{ $money($line->unit_price) }}</td>
                                <td class="px-3 py-3 text-right">{{ $line->sub_total->value === 0 && $line->type === 'shipping' ? 'Free' : $money($line->sub_total) }}</td>
                                <td class="px-3 py-3 text-right">
                                    @if ((float) $line->tax_breakdown->amounts->sum('percentage') === 0.0)
                                        <span data-zero-rated>0% (zero-rated)</span>
                                    @else
                                        {{ $money($line->tax_total) }} <span class="text-xs text-ink-muted">({{ $rate($line) }}%)</span>
                                    @endif
                                </td>
                                <td class="py-3 pl-3 text-right font-semibold">{{ $money($line->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <dl class="ml-auto mt-6 max-w-xs space-y-2 text-sm" data-invoice-totals>
                @if ($order->discount_total->value > 0)
                    <div class="flex justify-between gap-4" data-invoice-discount><dt>Discount (excl. VAT)</dt><dd class="font-semibold">-{{ $money($order->discount_total) }}</dd></div>
                @endif
                <div class="flex justify-between gap-4"><dt>Net total</dt><dd class="font-semibold">{{ $money(new \Lunar\DataTypes\Price($order->total->value - $order->tax_total->value, $order->currency, 1)) }}</dd></div>
                <div class="flex justify-between gap-4"><dt>VAT</dt><dd class="font-semibold">{{ $money($order->tax_total) }}</dd></div>
                <div class="flex justify-between gap-4 border-t border-border pt-2 text-lg font-bold"><dt>Total due</dt><dd data-invoice-total>{{ $money($order->total) }}</dd></div>
                @unless ($invoice->isPaid())
                    <p class="text-xs text-ink-muted">Payable within {{ \App\Domain\Orders\Invoice::TERMS_DAYS }} days, by {{ $invoice->dueDate()->format('j F Y') }}.</p>
                @endunless
            </dl>

            <footer class="mt-10 border-t border-border pt-4 text-xs text-ink-muted">
                {{ \App\Support\Store::disclosure() }}
            </footer>
        </article>
    </div>
</x-layouts.checkout>
