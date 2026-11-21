{{-- Plain text email to staff: values are not HTML-escaped, because a text part is never rendered as HTML. --}}
A new order has been placed on the website.

Order: {!! $reference !!}
Invoice number: {!! $invoiceNumber !!}
Placed: {!! $placed !!}
Payment: {!! $payment !!}
@if ($poNumber)
PO number: {!! $poNumber !!}
@endif
@if ($supplierNumber)
Supplier number: {!! $supplierNumber !!}
@endif

CUSTOMER
{!! $company !!}
Contact: {!! $contactName !!}
Email: {!! $contactEmail !!}
Phone: {!! $contactPhone !!}
@if ($vatNumber)
VAT number: {!! $vatNumber !!}
@endif

DELIVER TO
@foreach ($deliverTo as $line)
{!! $line !!}
@endforeach
@if ($instructions)

Delivery instructions: {!! $instructions !!}
@endif

BILL TO
@foreach ($billTo as $line)
{!! $line !!}
@endforeach

ITEMS (prices ex VAT)
@foreach ($items as $item)
- {!! $item !!}
@endforeach

Subtotal: {!! $subtotal !!}
@if ($discount)
Discount: -{!! $discount !!}
@endif
Delivery: {!! $delivery !!}
VAT: {!! $vat !!}
TOTAL: {!! $total !!}

View the order in the admin:
{!! $adminUrl !!}
