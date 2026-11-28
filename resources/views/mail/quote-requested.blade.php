{{-- Plain text email: values are not HTML-escaped, because a text part is never rendered as HTML. --}}
New quote request from the website.

Name: {!! $name !!}
Email: {!! $email !!}
Phone: {!! $phone ?: 'Not given' !!}
Company: {!! $company ?: 'Not given' !!}

What the quote is for:
@foreach ($items as $item)
- {!! $item['name'] !!}@if (filled($item['detail'])) ({!! $item['detail'] !!})@endif @if (filled($item['quantity'])) x {!! $item['quantity'] !!}@endif

@endforeach
@if (filled($quantity))
Quantity wanted: {!! $quantity !!}
@endif
@if (filled($body))

{!! $body !!}
@endif
