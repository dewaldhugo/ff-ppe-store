{{--
    The email staff get when an order is placed (App\Mail\OrderPlaced). HTML for email clients: table layout
    and inline styles, because most clients ignore a stylesheet and CSS variables. No colour is written here:
    every one comes from $colours, the site's own design tokens (App\Support\EmailColours), so the email
    follows the brand and DesignTokensTest still finds no literal colour in a template. The plain text part
    is mail/order-placed-text. Every value is escaped with {{ }}.
--}}
@php
    $c = $colours;
    $font = 'font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif;';
    $body = $font.' font-size: 14px; line-height: 21px; color: '.$c['ink'].';';
    $muted = $font.' font-size: 13px; line-height: 19px; color: '.$c['ink-muted'].';';
    $label = $font.' font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: '.$c['ink-muted'].';';
    $rule = 'border-top: 1px solid '.$c['border'].';';

    $summary = array_filter([
        'Order' => $reference,
        'Invoice number' => $invoiceNumber,
        'Placed' => $placed,
        'Payment' => $payment,
        'PO number' => $poNumber,
        'Supplier number' => $supplierNumber,
    ], fn ($value): bool => filled($value));
@endphp
<!DOCTYPE html>
<html lang="en-GB" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>New order {{ $reference }}</title>
    <style>
        @media only screen and (max-width: 520px) {
            .stack { display: block !important; width: 100% !important; box-sizing: border-box !important; }
            .pad { padding-left: 16px !important; padding-right: 16px !important; }
            .gap { padding-top: 16px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: {{ $c['surface-muted'] }};">
    {{-- Shown in the inbox list beside the subject, hidden in the email itself. --}}
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: {{ $c['surface-muted'] }};">
        {{ $company }} placed an order for {{ $total }}.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: {{ $c['surface-muted'] }};">
        <tr>
            <td align="center" style="padding: 24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width: 100%; max-width: 600px; background-color: {{ $c['white'] }}; border: 1px solid {{ $c['border'] }};">
                    {{-- Header --}}
                    <tr>
                        <td class="pad" style="background-color: {{ $c['brand-navy'] }}; padding: 20px 28px;">
                            <img src="{{ $logoUrl }}" width="180" alt="{{ $siteName }}" style="display: block; border: 0; outline: none; height: auto; max-width: 180px; color: {{ $c['white'] }}; {{ $font }} font-size: 18px; font-weight: 700;">
                        </td>
                    </tr>

                    {{-- Title --}}
                    <tr>
                        <td class="pad" style="padding: 28px 28px 8px 28px;">
                            <div style="{{ $label }}">New order</div>
                            <h1 style="margin: 4px 0 0 0; {{ $font }} font-size: 26px; line-height: 32px; font-weight: 700; color: {{ $c['brand-navy'] }};">{{ $reference }}</h1>
                            <div style="margin-top: 4px; {{ $body }} font-size: 16px;">from <strong>{{ $company }}</strong></div>
                            <div style="margin-top: 12px; {{ $font }} font-size: 22px; line-height: 28px; font-weight: 700; color: {{ $c['ink'] }};">{{ $total }} <span style="{{ $muted }}">inc VAT</span></div>
                        </td>
                    </tr>

                    {{-- The order --}}
                    <tr>
                        <td class="pad" style="padding: 20px 28px 8px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-order-summary>
                                @foreach ($summary as $name => $value)
                                    <tr>
                                        <td width="38%" valign="top" style="padding: 7px 12px 7px 0; {{ $rule }} {{ $muted }}">{{ $name }}</td>
                                        <td valign="top" style="padding: 7px 0; {{ $rule }} {{ $body }} font-weight: 600;">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>

                    {{-- The customer --}}
                    <tr>
                        <td class="pad" style="padding: 20px 28px 4px 28px;">
                            <div style="{{ $label }}">Customer</div>
                            <div style="margin-top: 6px; {{ $body }}" data-customer>
                                <strong>{{ $company }}</strong><br>
                                {{ $contactName }}<br>
                                @if (filled($contactEmail))
                                    <a href="mailto:{{ $contactEmail }}" style="color: {{ $c['brand-blue'] }}; text-decoration: underline;">{{ $contactEmail }}</a><br>
                                @endif
                                @if (filled($contactPhone))
                                    {{ $contactPhone }}<br>
                                @endif
                                @if ($vatNumber)
                                    VAT number {{ $vatNumber }}
                                @endif
                            </div>
                        </td>
                    </tr>

                    {{-- Where it goes and who pays --}}
                    <tr>
                        <td class="pad" style="padding: 20px 28px 4px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td class="stack" width="50%" valign="top" style="padding-right: 12px;" data-deliver-to>
                                        <div style="{{ $label }}">Deliver to</div>
                                        <div style="margin-top: 6px; {{ $body }}">
                                            @foreach ($deliverTo as $line)
                                                {{ $line }}<br>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="stack gap" width="50%" valign="top" style="padding-left: 12px;" data-bill-to>
                                        <div style="{{ $label }}">Bill to</div>
                                        <div style="margin-top: 6px; {{ $body }}">
                                            @foreach ($billTo as $line)
                                                {{ $line }}<br>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            @if ($instructions)
                                <div style="margin-top: 16px; padding: 12px 14px; background-color: {{ $c['surface-muted'] }}; border-left: 4px solid {{ $c['brand-teal'] }}; {{ $body }}" data-instructions>
                                    <strong>Delivery instructions</strong><br>
                                    {!! nl2br(e($instructions)) !!}
                                </div>
                            @endif
                        </td>
                    </tr>

                    {{-- Items --}}
                    <tr>
                        <td class="pad" style="padding: 24px 28px 0 28px;">
                            <div style="{{ $label }}">Items <span style="font-weight: 400; text-transform: none; letter-spacing: 0;">(prices ex VAT)</span></div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 8px;" data-items>
                                <tr>
                                    <td width="36" style="padding: 8px 0; border-bottom: 2px solid {{ $c['brand-navy'] }}; {{ $label }}">Qty</td>
                                    <td style="padding: 8px 8px; border-bottom: 2px solid {{ $c['brand-navy'] }}; {{ $label }}">Item</td>
                                    <td width="64" align="right" style="padding: 8px 0 8px 8px; border-bottom: 2px solid {{ $c['brand-navy'] }}; {{ $label }}">Each</td>
                                    <td width="72" align="right" style="padding: 8px 0 8px 8px; border-bottom: 2px solid {{ $c['brand-navy'] }}; {{ $label }}">Amount</td>
                                </tr>
                                @foreach ($lines as $line)
                                    <tr data-item>
                                        <td valign="top" style="padding: 10px 0; {{ $rule }} {{ $body }} font-weight: 700;">{{ $line['quantity'] }}</td>
                                        <td valign="top" style="padding: 10px 8px; {{ $rule }} {{ $body }}">
                                            <strong>{{ $line['description'] }}</strong>
                                            @if ($line['details'] !== '')
                                                <br><span style="{{ $muted }}">{{ $line['details'] }}</span>
                                            @endif
                                            <br><span style="{{ $muted }}">Code {{ $line['code'] }}</span>
                                        </td>
                                        <td valign="top" align="right" style="padding: 10px 0 10px 8px; {{ $rule }} {{ $body }}">{{ $line['unit'] }}</td>
                                        <td valign="top" align="right" style="padding: 10px 0 10px 8px; {{ $rule }} {{ $body }} font-weight: 600;">{{ $line['amount'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>

                    {{-- Totals --}}
                    <tr>
                        <td class="pad" style="padding: 8px 28px 4px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-totals>
                                <tr>
                                    <td align="right" style="padding: 5px 8px 5px 0; {{ $rule }} {{ $muted }}">Subtotal</td>
                                    <td width="96" align="right" style="padding: 5px 0; {{ $rule }} {{ $body }}">{{ $subtotal }}</td>
                                </tr>
                                @if ($discount)
                                    <tr>
                                        <td align="right" style="padding: 5px 8px 5px 0; {{ $muted }}">Discount</td>
                                        <td align="right" style="padding: 5px 0; {{ $body }}">-{{ $discount }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td align="right" style="padding: 5px 8px 5px 0; {{ $muted }}">Delivery</td>
                                    <td align="right" style="padding: 5px 0; {{ $body }}">{{ $delivery }}</td>
                                </tr>
                                <tr>
                                    <td align="right" style="padding: 5px 8px 5px 0; {{ $muted }}">VAT</td>
                                    <td align="right" style="padding: 5px 0; {{ $body }}">{{ $vat }}</td>
                                </tr>
                                <tr>
                                    <td align="right" style="padding: 10px 8px 6px 0; border-top: 2px solid {{ $c['brand-navy'] }}; {{ $body }} font-weight: 700;">Total</td>
                                    <td align="right" style="padding: 10px 0 6px 0; border-top: 2px solid {{ $c['brand-navy'] }}; {{ $font }} font-size: 18px; line-height: 24px; font-weight: 700; color: {{ $c['ink'] }};">{{ $total }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- The way in --}}
                    <tr>
                        <td class="pad" style="padding: 20px 28px 28px 28px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="background-color: {{ $c['brand-navy'] }}; border-radius: 6px;">
                                        <a href="{{ $adminUrl }}" data-admin-link style="display: inline-block; padding: 12px 24px; {{ $font }} font-size: 15px; font-weight: 700; color: {{ $c['white'] }}; text-decoration: none;">View the order in the admin</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td class="pad" style="padding: 16px 28px; background-color: {{ $c['surface-muted'] }}; {{ $rule }} {{ $muted }} font-size: 12px;">
                            You are getting this because a customer placed an order on the {{ $siteName }} website.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
