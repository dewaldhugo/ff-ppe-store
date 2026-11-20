@props(['light' => false])

{{-- The logo: the black file on light surfaces, the white file on dark ones. Its size follows the height. --}}
<img
    src="{{ asset($light ? 'images/frontline-logo-white.png' : 'images/frontline-logo.png') }}"
    alt="{{ \App\Support\Store::name() }}"
    width="820"
    height="140"
    {{ $attributes->class('h-7 w-auto sm:h-9') }}
>
