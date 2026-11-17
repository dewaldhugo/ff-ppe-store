{{-- Plain text email: values are not HTML-escaped, because a text part is never rendered as HTML. --}}
New enquiry from the website.

Name: {!! $name !!}
Email: {!! $email !!}
Phone: {!! $phone ?: 'Not given' !!}
Subject: {!! $enquirySubject !!}

{!! $body !!}
