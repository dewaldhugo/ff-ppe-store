@php
    $money = fn ($price): string => (string) $price->formatted(trimTrailingZeros: false);
    $rate = fn (float $rate): string => rtrim(rtrim(number_format($rate, 2), '0'), '.').'%';
    $goods = $invoice->goodsLines();
    $billing = $order->billingAddress;
    $shipping = $order->shippingAddress;
    $address = fn ($a): array => array_values(array_filter([trim($a?->first_name.' '.$a?->last_name), $a?->line_one, $a?->line_two, $a?->city, $a?->state, $a?->postcode]));
    $meta = fn ($line, string $key): ?string => filled($line->meta[$key] ?? null) ? (string) $line->meta[$key] : null;

    // Colour and size columns show only when some line has one. "UK Size" unless a line has another kind of size.
    $showColour = $goods->contains(fn ($line): bool => $meta($line, 'colour') !== null);
    $showSize = $goods->contains(fn ($line): bool => $meta($line, 'size') !== null);
    $showEu = $goods->contains(fn ($line): bool => $meta($line, 'eu_size') !== null);
    $sizeHeading = $goods->contains(fn ($line): bool => $meta($line, 'size') !== null && ! ($line->meta['size_is_uk'] ?? false)) ? 'Size' : 'UK Size';

    $vatRate = $invoice->singleRate();
    $dueDate = $invoice->dueDate();
    $payNow = $invoice->needsPayment();
    $customerName = $billing?->company_name ?: trim($billing?->first_name.' '.$billing?->last_name);
@endphp

{{--
    A tax invoice for one placed order, laid out to print (the browser's Print / Save as PDF) like the invoice sample the
    client supplied: title and customer on the left, the invoice details in the middle, the supplier on the right, the
    lines, the totals, then (while an account invoice is unpaid) the due date, the bank details and a payment advice
    slip. The buttons and the site header are left out of the print. The supplier's numbers come from config/store.php.
--}}
<x-layouts.checkout :title="'Invoice '.$invoice->number()">
    <div class="mx-auto max-w-4xl" data-invoice>
        <p class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a href="{{ route('account.invoices') }}" class="text-sm font-semibold text-brand-blue underline">&larr; All invoices</a>
            <button type="button" class="rounded-md bg-brand-blue px-5 py-2 text-sm font-semibold text-white hover:bg-brand-navy" x-data x-on:click="window.print()" data-print-invoice>Print or save as PDF</button>
        </p>

        <article class="rounded border border-border bg-white p-6 sm:p-10 print:border-0 print:p-0">
            <header class="grid gap-6 text-sm sm:grid-cols-3">
                <div>
                    <h1 class="text-3xl font-bold uppercase tracking-wide">Tax invoice</h1>

                    <div class="mt-6" data-invoice-billing>
                        @if ($customerName)
                            <p class="font-bold">{{ $customerName }}</p>
                        @endif
                        @foreach ($address($billing) as $line)
                            <p class="text-ink-muted">{{ $line }}</p>
                        @endforeach
                    </div>

                    @if ($shipping)
                        <div class="mt-4" data-invoice-delivery>
                            <p class="font-bold">Delivered to</p>
                            @if ($shipping->company_name)
                                <p class="text-ink-muted">{{ $shipping->company_name }}</p>
                            @endif
                            @foreach ($address($shipping) as $line)
                                <p class="text-ink-muted">{{ $line }}</p>
                            @endforeach
                        </div>
                    @endif
                </div>

                <dl class="space-y-4">
                    <div><dt class="font-bold">Invoice Date</dt><dd class="text-ink-muted" data-invoice-date>{{ $invoice->date()->format('j M Y') }}</dd></div>
                    <div><dt class="font-bold">Invoice Number</dt><dd class="text-ink-muted" data-invoice-number>{{ $invoice->number() }}</dd></div>
                    <div><dt class="font-bold">Order</dt><dd class="text-ink-muted" data-invoice-order>{{ $order->reference }}</dd></div>
                    @if ($order->customer_reference)
                        <div><dt class="font-bold">Purchase Order Number</dt><dd class="text-ink-muted" data-invoice-po>{{ $order->customer_reference }}</dd></div>
                    @endif
                    @if ($billing?->tax_identifier)
                        <div><dt class="font-bold">VAT Number</dt><dd class="text-ink-muted" data-invoice-customer-vat>{{ $billing->tax_identifier }}</dd></div>
                    @endif
                </dl>

                <div data-invoice-supplier>
                    <p class="font-bold">{{ \App\Support\Store::legalName() }}</p>
                    <p class="text-ink-muted">{{ \App\Support\Store::address() }}</p>
                    <p class="mt-3 text-ink-muted" data-supplier-vat>VAT Number: {{ \App\Support\Store::vatNumber() }}</p>
                    <p class="mt-3 text-ink-muted">{{ \App\Support\Store::email() }}</p>
                </div>
            </header>

            <div class="mt-10 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm" data-invoice-lines>
                    <thead class="border-y border-border text-xs font-bold text-ink">
                        <tr>
                            <th scope="col" class="py-2 pr-3">Description</th>
                            @if ($showColour)<th scope="col" class="px-3 py-2">Colour</th>@endif
                            @if ($showSize)<th scope="col" class="px-3 py-2">{{ $sizeHeading }}</th>@endif
                            @if ($showEu)<th scope="col" class="px-3 py-2">EU Size</th>@endif
                            <th scope="col" class="px-3 py-2 text-right">Quantity</th>
                            <th scope="col" class="px-3 py-2 text-right">Unit Price</th>
                            <th scope="col" class="px-3 py-2 text-right">VAT</th>
                            <th scope="col" class="py-2 pl-3 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($goods as $line)
                            @php($snapshotted = $meta($line, 'colour') !== null || $meta($line, 'size') !== null)
                            <tr data-invoice-line>
                                <td class="py-3 pr-3">
                                    <span class="font-semibold">{{ $line->description }}</span>
                                    <span class="block text-xs text-ink-muted">Code {{ $line->identifier }}@if (! $snapshotted && $line->option), {{ $line->option }}@endif</span>
                                </td>
                                @if ($showColour)<td class="px-3 py-3" data-line-colour>{{ $meta($line, 'colour') }}</td>@endif
                                @if ($showSize)<td class="px-3 py-3" data-line-size>{{ $meta($line, 'size') }}</td>@endif
                                @if ($showEu)<td class="px-3 py-3" data-line-eu>{{ $meta($line, 'eu_size') }}</td>@endif
                                <td class="px-3 py-3 text-right">{{ $line->quantity }}</td>
                                <td class="px-3 py-3 text-right">{{ $money($line->unit_price) }}</td>
                                <td class="px-3 py-3 text-right">
                                    @if ($invoice->rate($line) === 0.0)
                                        <span data-zero-rated>0%</span>
                                    @else
                                        {{ $rate($invoice->rate($line)) }}
                                    @endif
                                </td>
                                <td class="py-3 pl-3 text-right font-semibold">{{ $money($line->sub_total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <dl class="ml-auto mt-6 max-w-xs space-y-2 text-sm" data-invoice-totals>
                <div class="flex justify-between gap-4" data-invoice-subtotal><dt>Subtotal</dt><dd class="font-semibold">{{ $money($invoice->subtotal()) }}</dd></div>
                @if ($order->discount_total->value > 0)
                    <div class="flex justify-between gap-4" data-invoice-discount><dt>Discount</dt><dd class="font-semibold">-{{ $money($order->discount_total) }}</dd></div>
                @endif
                <div class="flex justify-between gap-4" data-invoice-delivery-total><dt>Delivery</dt><dd class="font-semibold">{{ $money($invoice->delivery()) }}</dd></div>
                <div class="flex justify-between gap-4" data-invoice-vat><dt>TOTAL VAT{{ $vatRate !== null && $vatRate > 0 ? ' '.$rate($vatRate) : '' }}</dt><dd class="font-semibold">{{ $money($order->tax_total) }}</dd></div>
                <div class="flex justify-between gap-4 border-t border-border pt-2 text-lg font-bold"><dt>TOTAL</dt><dd data-invoice-total>{{ $money($order->total) }}</dd></div>
            </dl>

            <p class="mt-8 text-sm" data-invoice-payment-line>
                @if ($invoice->isPaid())
                    <span class="font-bold">Status:</span> <span data-invoice-status>Paid</span>
                @else
                    <span class="font-bold">Due Date:</span> <span data-invoice-status>{{ $dueDate->format('j M Y') }}</span>
                @endif
            </p>

            @if ($payNow)
                <section class="mt-6 text-sm" aria-labelledby="pay-heading" data-invoice-bank>
                    <h2 id="pay-heading" class="font-bold">Payment details</h2>
                    <p class="text-ink-muted">Please pay by bank transfer, quoting the invoice number.</p>
                    <dl class="mt-2 grid max-w-md grid-cols-[8rem_1fr] gap-x-4 gap-y-1">
                        @foreach (\App\Support\Store::bankDetails() as $label => $value)
                            <dt class="text-ink-muted">{{ $label }}</dt>
                            <dd class="font-semibold">{{ $value }}</dd>
                        @endforeach
                    </dl>
                </section>

                {{-- The slip the customer sends back, or quotes, with their payment. On its own page when printed. --}}
                <section class="mt-10 border-t-2 border-dashed border-border pt-6 text-sm print:break-before-page" aria-labelledby="advice-heading" data-payment-advice>
                    <h2 id="advice-heading" class="text-xl font-bold uppercase tracking-wide">Payment advice</h2>

                    <div class="mt-4 grid gap-6 sm:grid-cols-2">
                        <div data-advice-customer>
                            @if ($customerName)
                                <p class="font-bold">{{ $customerName }}</p>
                            @endif
                            @foreach ($address($billing) as $line)
                                <p class="text-ink-muted">{{ $line }}</p>
                            @endforeach
                        </div>

                        <dl class="space-y-3">
                            <div><dt class="font-bold">Customer</dt><dd class="text-ink-muted">{{ $customerName }}</dd></div>
                            <div><dt class="font-bold">Invoice Number</dt><dd class="text-ink-muted" data-advice-number>{{ $invoice->number() }}</dd></div>
                            <div><dt class="font-bold">Amount Due</dt><dd class="text-ink-muted" data-advice-amount>{{ $money($order->total) }}</dd></div>
                            <div><dt class="font-bold">Due Date</dt><dd class="text-ink-muted" data-advice-due>{{ $dueDate->format('j M Y') }}</dd></div>
                            <div>
                                <dt class="font-bold">Amount Enclosed</dt>
                                <dd class="mt-6 border-b border-ink" data-advice-enclosed></dd>
                                <dd class="mt-1 text-xs text-ink-muted">Enter the amount you are paying above</dd>
                            </div>
                        </dl>
                    </div>
                </section>
            @endif

            <footer class="mt-10 border-t border-border pt-4 text-xs text-ink-muted" data-invoice-footer>
                Company Registration No: {{ \App\Support\Store::companyNumber() }}. {{ \App\Support\Store::legalName() }}, registered in England and Wales. Registered Office: {{ \App\Support\Store::address() }}.
            </footer>
        </article>
    </div>
</x-layouts.checkout>
