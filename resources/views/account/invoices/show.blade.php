@php
    // The pound sign is on the TOTAL only: every other amount is a plain number.
    $money = fn ($price): string => (string) $price->formatted(trimTrailingZeros: false);
    $amount = fn ($price): string => \App\Domain\Orders\Invoice::amount($price);
    $rate = fn (float $rate): string => rtrim(rtrim(number_format($rate, 2), '0'), '.').'%';
    $goods = $invoice->goodsLines();
    $billing = $order->billingAddress;
    $shipping = $order->shippingAddress;
    $contact = fn ($a): string => trim($a?->first_name.' '.$a?->last_name);
    $address = fn ($a): array => array_values(array_filter([$a?->line_one, $a?->line_two, $a?->city, $a?->state, $a?->postcode]));
    $meta = fn ($line, string $key): ?string => filled($line->meta[$key] ?? null) ? (string) $line->meta[$key] : null;

    // Colour and size columns show only when some line has one. "UK Size" unless a line has another kind of size.
    $showColour = $goods->contains(fn ($line): bool => $meta($line, 'colour') !== null);
    $showEu = $goods->contains(fn ($line): bool => $meta($line, 'eu_size') !== null);
    // The UK size always comes with the EU size, in the column before it (empty for a line that has none).
    $showSize = $showEu || $goods->contains(fn ($line): bool => $meta($line, 'size') !== null);
    $sizeHeading = $goods->contains(fn ($line): bool => $meta($line, 'size') !== null && ! ($line->meta['size_is_uk'] ?? false)) ? 'Size' : 'UK Size';

    // Description, Quantity, Unit Price and VAT, plus Colour, Size and EU Size when shown: the totals' labels span them.
    $labelSpan = 4 + ($showColour ? 1 : 0) + ($showSize ? 1 : 0) + ($showEu ? 1 : 0);
    $vatRate = $invoice->singleRate();
    $dueDate = $invoice->dueDate();
    $customerName = $billing?->company_name ?: $contact($billing);

    // The lines table's header: light grey with a thick black rule under it, as in the client's sample invoice.
    $head = 'bg-surface-muted text-ink border-b-[3px] border-ink print:[print-color-adjust:exact] print:[-webkit-print-color-adjust:exact]';

    // The dark bars: the colours must print, which browsers switch off for backgrounds unless asked.
    $bar = 'bg-brand-blue text-white print:[print-color-adjust:exact] print:[-webkit-print-color-adjust:exact]';
@endphp

{{--
    The invoice, laid out to print (the browser's Print / Save as PDF) from the client's template: the logo and the
    sender top left, INVOICE and its details top right, Bill to and Deliver to boxes, the lines and totals, a rule,
    then the payment method with the due date and bank details (30 day account) or "Status: Paid" (card), and the footer.
    The sender, bank and registration details are fixed in config/store.php. The site header is left out of the print.
--}}
<x-layouts.checkout :title="'Invoice '.$invoice->number()">
    {{--
        Printing and Save as PDF: one A4 page with no browser header or footer (the web address, the page title, the date
        and the page numbers). Browsers print those in the page margin, so a margin of 0 leaves them no room, and the
        article carries its own padding. A smaller root size scales every rem-based size down together, so a normal
        invoice fits on the page; a very long one runs on to a second page.
    --}}
    <style>
        @page { size: A4; margin: 0; }
        @media print { html { font-size: 12px; } }
    </style>

    <div class="mx-auto max-w-4xl print:max-w-none" data-invoice>
        <p class="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a href="{{ route('account.invoices') }}" class="text-sm font-semibold text-brand-blue underline">&larr; All invoices</a>
            <button type="button" class="rounded-md bg-brand-blue px-5 py-2 text-sm font-semibold text-white hover:bg-brand-navy" x-data x-on:click="window.print()" data-print-invoice>Print or save as PDF</button>
        </p>

        <article class="rounded border border-border bg-white p-6 sm:p-10 print:border-0 print:p-[11mm]">
            <header class="grid gap-8 sm:grid-cols-2 print:grid-cols-2 print:gap-4">
                <div data-invoice-supplier>
                    <img src="{{ asset('images/frontline-logo.png') }}" alt="Frontline Safety Gear" width="820" height="140" class="h-10 w-auto" data-invoice-logo>
                    <p class="mt-4 text-xl font-bold" data-supplier-name>{{ \App\Support\Store::invoice('name') }}</p>
                    <p class="text-sm">{{ \App\Support\Store::invoice('trading_as') }}</p>
                    <p class="text-sm">{{ \App\Support\Store::invoice('address') }}</p>
                    <p class="text-sm" data-supplier-vat>VAT Number: {{ \App\Support\Store::invoice('vat_number') }}</p>
                    <p class="text-sm">{{ \App\Support\Store::invoice('email') }}</p>
                    <p class="text-sm">{{ \App\Support\Store::invoice('website') }}</p>
                </div>

                <div class="sm:text-right">
                    <h1 class="text-5xl font-extrabold uppercase tracking-tight">Tax invoice</h1>

                    <dl class="mt-4 space-y-1 text-sm" data-invoice-details>
                        <div><dt class="inline font-bold">Invoice Date:</dt> <dd class="inline" data-invoice-date>{{ $invoice->date()->format('j M Y') }}</dd></div>
                        <div><dt class="inline font-bold">Invoice Number:</dt> <dd class="inline" data-invoice-number>{{ $invoice->number() }}</dd></div>
                        @if ($order->customer_reference)
                            <div><dt class="inline font-bold">Purchase Order Number:</dt> <dd class="inline" data-invoice-po>{{ $order->customer_reference }}</dd></div>
                        @endif
                        @if ($billing?->tax_identifier)
                            <div><dt class="inline font-bold">VAT Number:</dt> <dd class="inline" data-invoice-customer-vat>{{ $billing->tax_identifier }}</dd></div>
                        @endif
                        @if ($invoice->supplierNumber())
                            <div><dt class="inline font-bold">Supplier Number:</dt> <dd class="inline" data-invoice-supplier-number>{{ $invoice->supplierNumber() }}</dd></div>
                        @endif
                    </dl>
                </div>
            </header>

            <hr class="my-6 border-t-4 border-border print:my-3">

            <div class="grid gap-6 text-sm sm:grid-cols-2 print:grid-cols-2 print:gap-4">
                <section class="border border-border" aria-labelledby="bill-to-heading" data-invoice-billing>
                    <h2 id="bill-to-heading" class="{{ $bar }} px-3 py-2 print:py-1 text-sm font-bold uppercase tracking-wide">Bill to</h2>
                    <div class="min-h-24 p-3 print:min-h-0 print:p-2">
                        @if ($customerName)
                            <p class="font-bold">{{ $customerName }}</p>
                        @endif
                        @if ($billing?->company_name && $contact($billing))
                            <p>{{ $contact($billing) }}</p>
                        @endif
                        @foreach ($address($billing) as $line)
                            <p>{{ $line }}</p>
                        @endforeach
                        @if ($billing?->tax_identifier)
                            <p class="mt-1">VAT Number: {{ $billing->tax_identifier }}</p>
                        @endif
                    </div>
                </section>

                <section class="border border-border" aria-labelledby="deliver-to-heading" data-invoice-delivery>
                    <h2 id="deliver-to-heading" class="{{ $bar }} px-3 py-2 print:py-1 text-sm font-bold uppercase tracking-wide">Deliver to</h2>
                    <div class="min-h-24 p-3 print:min-h-0 print:p-2">
                        @if ($shipping)
                            @if ($shipping->company_name)
                                <p class="font-bold">{{ $shipping->company_name }}</p>
                            @endif
                            @if ($contact($shipping))
                                <p>{{ $contact($shipping) }}</p>
                            @endif
                            @foreach ($address($shipping) as $line)
                                <p>{{ $line }}</p>
                            @endforeach
                        @endif
                    </div>
                </section>
            </div>

            <div class="mt-8 overflow-x-auto print:mt-4">
                <table class="w-full min-w-[40rem] text-left text-sm" data-invoice-lines>
                    <thead class="{{ $head }} text-sm font-bold">
                        <tr>
                            <th scope="col" class="px-3 py-2 print:py-1">Description</th>
                            @if ($showColour)<th scope="col" class="px-3 py-2 print:py-1">Colour</th>@endif
                            @if ($showSize)<th scope="col" class="px-3 py-2 print:py-1">{{ $sizeHeading }}</th>@endif
                            @if ($showEu)<th scope="col" class="px-3 py-2 print:py-1">EU Size</th>@endif
                            {{-- Quantity and Unit Price are left-aligned with their figures; VAT and Amount are right-aligned with theirs. --}}
                            <th scope="col" class="px-3 py-2 print:py-1 text-left">Quantity</th>
                            <th scope="col" class="px-3 py-2 print:py-1 text-left">Unit Price</th>
                            <th scope="col" class="px-3 py-2 print:py-1 text-right">VAT</th>
                            <th scope="col" class="px-3 py-2 print:py-1 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($goods as $line)
                            @php($snapshotted = $meta($line, 'colour') !== null || $meta($line, 'size') !== null)
                            <tr class="border-b border-border print:break-inside-avoid" data-invoice-line>
                                <td class="px-3 py-3 print:py-1.5">
                                    <span class="font-semibold">{{ $line->description }}</span>
                                    <span class="block text-xs text-ink-muted print:ml-2 print:inline">Code {{ $line->identifier }}@if (! $snapshotted && $line->option), {{ $line->option }}@endif</span>
                                </td>
                                @if ($showColour)<td class="px-3 py-3 print:py-1.5" data-line-colour>{{ $meta($line, 'colour') }}</td>@endif
                                @if ($showSize)<td class="px-3 py-3 print:py-1.5" data-line-size>{{ $meta($line, 'size') }}</td>@endif
                                @if ($showEu)<td class="px-3 py-3 print:py-1.5" data-line-eu>{{ $meta($line, 'eu_size') }}</td>@endif
                                <td class="px-3 py-3 print:py-1.5 text-left" data-line-quantity>{{ $line->quantity }}</td>
                                <td class="px-3 py-3 print:py-1.5 text-left" data-line-unit-price>{{ $amount($line->unit_price) }}</td>
                                <td class="px-3 py-3 print:py-1.5 text-right">
                                    @if ($invoice->rate($line) === 0.0)
                                        <span data-zero-rated>0%</span>
                                    @else
                                        {{ $rate($invoice->rate($line)) }}
                                    @endif
                                </td>
                                <td class="px-3 py-3 print:py-1.5 text-right font-semibold">{{ $amount($line->sub_total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    {{-- The totals carry on from the lines: the same table, the same borders. A second tbody, not a tfoot, so a printed invoice that runs to a second page does not repeat them on every page. --}}
                    <tbody data-invoice-totals>
                        <tr data-invoice-subtotal>
                            <th scope="row" colspan="{{ $labelSpan }}" class="px-3 py-2 print:py-1 text-right font-normal">Subtotal</th>
                            <td class="px-3 py-2 print:py-1 text-right font-semibold">{{ $amount($invoice->subtotal()) }}</td>
                        </tr>
                        @if ($order->discount_total->value > 0)
                            <tr data-invoice-discount>
                                <th scope="row" colspan="{{ $labelSpan }}" class="px-3 py-2 print:py-1 text-right font-normal">Discount</th>
                                <td class="px-3 py-2 print:py-1 text-right font-semibold">-{{ $amount($order->discount_total) }}</td>
                            </tr>
                        @endif
                        <tr data-invoice-delivery-total>
                            <th scope="row" colspan="{{ $labelSpan }}" class="px-3 py-2 print:py-1 text-right font-normal">Delivery</th>
                            <td class="px-3 py-2 print:py-1 text-right font-semibold">{{ $amount($invoice->delivery()) }}</td>
                        </tr>
                        <tr data-invoice-vat>
                            <th scope="row" colspan="{{ $labelSpan }}" class="px-3 py-2 print:py-1 text-right font-normal">TOTAL VAT{{ $vatRate !== null && $vatRate > 0 ? ' '.$rate($vatRate) : '' }}</th>
                            <td class="px-3 py-2 print:py-1 text-right font-semibold">{{ $amount($order->tax_total) }}</td>
                        </tr>
                        <tr class="border-t-[3px] border-ink text-base font-bold" data-invoice-grand>
                            <th scope="row" colspan="{{ $labelSpan }}" class="px-3 py-3 print:py-1.5 text-right">TOTAL</th>
                            <td class="px-3 py-3 print:py-1.5 text-right" data-invoice-total>{{ $money($order->total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <hr class="my-8 border-t border-ink-muted print:my-3">

            <section class="text-sm print:break-inside-avoid" aria-labelledby="payment-heading" data-invoice-payment>
                <h2 id="payment-heading" class="sr-only">Payment</h2>
                <p><span class="font-bold">Payment Method:</span> <span data-invoice-method>{{ $invoice->paymentMethodLabel() }}</span></p>

                @if ($invoice->needsPayment())
                    {{-- A 30 day account invoice still to pay: when, and where to. --}}
                    <p class="mt-2"><span class="font-bold">Due Date:</span> <span data-invoice-status>{{ $dueDate->format('j M Y') }}</span></p>

                    {{-- The account name and the bank on their own lines, then each detail with its label. --}}
                    <div class="mt-4 space-y-0.5" data-invoice-bank>
                        @foreach (\App\Support\Store::bankDetails() as $label => $value)
                            @if ($label === 'Account name' || $label === 'Bank')
                                <p @class(['font-bold' => $label === 'Account name'])>{{ $value }}</p>
                            @else
                                <p>{{ $label }}: {{ $value }}</p>
                            @endif
                        @endforeach
                    </div>
                @else
                    <p class="mt-2"><span class="font-bold">Status:</span> <span data-invoice-status>Paid</span></p>
                @endif
            </section>

            <footer class="mt-10 border-t border-border pt-4 text-xs text-ink-muted print:mt-4 print:pt-2" data-invoice-footer>
                Company Registration No: {{ \App\Support\Store::invoice('company_number') }}. Registered Office: {{ \App\Support\Store::invoice('registered_office') }}
            </footer>
        </article>
    </div>
</x-layouts.checkout>
