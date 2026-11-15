@props(['light' => false])

{{-- The logo: the blue file on light surfaces, the white file on dark ones. Its size follows the height. --}}
<img
    src="{{ asset($light ? 'images/the-bridge-group-logo-white.svg' : 'images/the-bridge-group-logo.svg') }}"
    alt="{{ \App\Support\Store::name() }}"
    width="286"
    height="32"
    {{ $attributes->class('h-6 w-auto sm:h-8') }}
>
