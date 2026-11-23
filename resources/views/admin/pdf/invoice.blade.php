@php
    // The same figures as the customer's invoice page (account/invoices/show), laid out for the PDF library: tables and
    // plain CSS only, colours passed in (a PDF cannot read the design tokens).
    $money = fn ($price): string => (string) $price->formatted(trimTrailingZeros: false);
    $amount = fn ($price): string => \App\Domain\Orders\Invoice::amount($price);
    $rate = fn (float $rate): string => rtrim(rtrim(number_format($rate, 2), '0'), '.').'%';
    $goods = $invoice->goodsLines();
    $billing = $order->billingAddress;
    $shipping = $order->shippingAddress;
    $contact = fn ($a): string => trim($a?->first_name.' '.$a?->last_name);
    $address = fn ($a): array => array_values(array_filter([$a?->line_one, $a?->line_two, $a?->city, $a?->state, $a?->postcode]));
    $meta = fn ($line, string $key): ?string => filled($line->meta[$key] ?? null) ? (string) $line->meta[$key] : null;

    $showColour = $goods->contains(fn ($line): bool => $meta($line, 'colour') !== null);
    $showSize = $goods->contains(fn ($line): bool => $meta($line, 'size') !== null);
    $showEu = $goods->contains(fn ($line): bool => $meta($line, 'eu_size') !== null);
    $sizeHeading = $goods->contains(fn ($line): bool => $meta($line, 'size') !== null && ! ($line->meta['size_is_uk'] ?? false)) ? 'Size' : 'UK Size';

    $labelSpan = 4 + ($showColour ? 1 : 0) + ($showSize ? 1 : 0) + ($showEu ? 1 : 0);
    $vatRate = $invoice->singleRate();
    $customerName = $billing?->company_name ?: $contact($billing);
@endphp
<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number() }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; font-size: 9px; color: {{ $colours['ink'] }}; }
        table { border-collapse: collapse; width: 100%; }
        p { margin: 0 0 2px 0; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .muted { color: {{ $colours['muted'] }}; }
        .bar { background-color: {{ $colours['bar'] }}; color: {{ $colours['bar_text'] }}; font-weight: bold; text-transform: uppercase; padding: 4px 8px; font-size: 9px; }
        .box { border: 1px solid {{ $colours['border'] }}; padding: 6px 8px; height: 62px; vertical-align: top; }
        .lines th { background-color: {{ $colours['head'] }}; border-bottom: 3px solid {{ $colours['ink'] }}; padding: 5px 6px; text-align: left; font-size: 9px; }
        .lines td { border-bottom: 1px solid {{ $colours['border'] }}; padding: 5px 6px; vertical-align: top; }
        .lines tr { page-break-inside: avoid; }
        .totals td, .totals th { padding: 4px 6px; font-weight: normal; }
        .grand td, .grand th { border-top: 3px solid {{ $colours['ink'] }}; font-size: 11px; font-weight: bold; padding: 6px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width: 52%; vertical-align: top;">
                <img src="{{ $logo }}" alt="Frontline Safety Gear" style="height: 34px;">
                <p class="bold" style="font-size: 15px; margin-top: 10px;">{{ \App\Support\Store::invoice('name') }}</p>
                <p>{{ \App\Support\Store::invoice('trading_as') }}</p>
                <p>{{ \App\Support\Store::invoice('address') }}</p>
                <p>VAT Number: {{ \App\Support\Store::invoice('vat_number') }}</p>
                <p>{{ \App\Support\Store::invoice('email') }}</p>
                <p>{{ \App\Support\Store::invoice('website') }}</p>
            </td>
            <td class="right" style="vertical-align: top;">
                <p class="bold" style="font-size: 26px; text-transform: uppercase;">Tax invoice</p>
                <p style="margin-top: 8px;"><span class="bold">Invoice Date:</span> {{ $invoice->date()->format('j M Y') }}</p>
                <p><span class="bold">Invoice Number:</span> {{ $invoice->number() }}</p>
                @if ($order->customer_reference)
                    <p><span class="bold">Purchase Order Number:</span> {{ $order->customer_reference }}</p>
                @endif
                @if ($billing?->tax_identifier)
                    <p><span class="bold">VAT Number:</span> {{ $billing->tax_identifier }}</p>
                @endif
                @if ($invoice->supplierNumber())
                    <p><span class="bold">Supplier Number:</span> {{ $invoice->supplierNumber() }}</p>
                @endif
            </td>
        </tr>
    </table>

    <div style="border-top: 3px solid {{ $colours['border'] }}; margin: 12px 0;"></div>

    <table>
        <tr>
            <td style="width: 49%; vertical-align: top;">
                <div class="bar">Bill to</div>
                <div class="box">
                    @if ($customerName)<p class="bold">{{ $customerName }}</p>@endif
                    @if ($billing?->company_name && $contact($billing))<p>{{ $contact($billing) }}</p>@endif
                    @foreach ($address($billing) as $line)<p>{{ $line }}</p>@endforeach
                    @if ($billing?->tax_identifier)<p>VAT Number: {{ $billing->tax_identifier }}</p>@endif
                </div>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 49%; vertical-align: top;">
                <div class="bar">Deliver to</div>
                <div class="box">
                    @if ($shipping)
                        @if ($shipping->company_name)<p class="bold">{{ $shipping->company_name }}</p>@endif
                        @if ($contact($shipping))<p>{{ $contact($shipping) }}</p>@endif
                        @foreach ($address($shipping) as $line)<p>{{ $line }}</p>@endforeach
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 16px;">
        <thead>
            <tr>
                <th>Description</th>
                @if ($showColour)<th>Colour</th>@endif
                @if ($showSize)<th>{{ $sizeHeading }}</th>@endif
                @if ($showEu)<th>EU Size</th>@endif
                <th class="right">Quantity</th>
                <th class="right">Unit Price</th>
                <th class="right">VAT</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($goods as $line)
                @php($snapshotted = $meta($line, 'colour') !== null || $meta($line, 'size') !== null)
                <tr>
                    <td>
                        <span class="bold">{{ $line->description }}</span><br>
                        <span class="muted" style="font-size: 8px;">Code {{ $line->identifier }}@if (! $snapshotted && $line->option), {{ $line->option }}@endif</span>
                    </td>
                    @if ($showColour)<td>{{ $meta($line, 'colour') }}</td>@endif
                    @if ($showSize)<td>{{ $meta($line, 'size') }}</td>@endif
                    @if ($showEu)<td>{{ $meta($line, 'eu_size') }}</td>@endif
                    <td class="right">{{ $line->quantity }}</td>
                    <td class="right">{{ $amount($line->unit_price) }}</td>
                    <td class="right">{{ $invoice->rate($line) === 0.0 ? '0%' : $rate($invoice->rate($line)) }}</td>
                    <td class="right bold">{{ $amount($line->sub_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><th colspan="{{ $labelSpan }}" class="right">Subtotal</th><td class="right bold" style="width: 13%;">{{ $amount($invoice->subtotal()) }}</td></tr>
        @if ($order->discount_total->value > 0)
            <tr><th colspan="{{ $labelSpan }}" class="right">Discount</th><td class="right bold">-{{ $amount($order->discount_total) }}</td></tr>
        @endif
        <tr><th colspan="{{ $labelSpan }}" class="right">Delivery</th><td class="right bold">{{ $amount($invoice->delivery()) }}</td></tr>
        <tr><th colspan="{{ $labelSpan }}" class="right">TOTAL VAT{{ $vatRate !== null && $vatRate > 0 ? ' '.$rate($vatRate) : '' }}</th><td class="right bold">{{ $amount($order->tax_total) }}</td></tr>
        <tr class="grand"><th colspan="{{ $labelSpan }}" class="right">TOTAL</th><td class="right">{{ $money($order->total) }}</td></tr>
    </table>

    <div style="border-top: 1px solid {{ $colours['muted'] }}; margin: 16px 0 8px;"></div>

    <div style="page-break-inside: avoid;">
        <p><span class="bold">Payment Method:</span> {{ $invoice->paymentMethodLabel() }}</p>

        @if ($invoice->needsPayment())
            <p style="margin-top: 4px;"><span class="bold">Due Date:</span> {{ $invoice->dueDate()->format('j M Y') }}</p>
            <div style="margin-top: 8px;">
                @foreach (\App\Support\Store::bankDetails() as $label => $value)
                    @if ($label === 'Account name' || $label === 'Bank')
                        <p @if ($label === 'Account name') class="bold" @endif>{{ $value }}</p>
                    @else
                        <p>{{ $label }}: {{ $value }}</p>
                    @endif
                @endforeach
            </div>
        @else
            <p style="margin-top: 4px;"><span class="bold">Status:</span> Paid</p>
        @endif
    </div>

    <div class="muted" style="border-top: 1px solid {{ $colours['border'] }}; margin-top: 18px; padding-top: 6px; font-size: 8px;">
        Company Registration No: {{ \App\Support\Store::invoice('company_number') }}. Registered Office: {{ \App\Support\Store::invoice('registered_office') }}
    </div>
</body>
</html>
