{{-- Plain text email: values are not HTML-escaped, because a text part is never rendered as HTML. --}}
New wear trial request from the website.

Name: {!! $name !!}
Company: {!! $company !!}
Email: {!! $email !!}
Phone: {!! $phone ?: 'Not given' !!}
Industry: {!! \App\Domain\Accounts\Industries::label($industry) ?? 'Not given' !!}
Products to trial: {!! $products !!}

{!! $details !!}
