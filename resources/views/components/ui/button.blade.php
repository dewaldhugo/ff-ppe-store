@props(['href' => null, 'type' => 'button', 'variant' => 'primary'])

{{--
    Primary button: brand-blue with white text. The accent variant is a brand-teal
    fill with brand-navy text (7.98:1), for use on navy surfaces. A link when it
    has an href.
--}}
@php
    $classes = 'inline-flex items-center justify-center rounded-md px-6 py-3 text-base font-semibold transition-colors disabled:cursor-not-allowed disabled:bg-disabled-surface disabled:text-disabled-ink '
        .($variant === 'accent'
            ? 'bg-brand-teal text-brand-navy hover:bg-white'
            : 'bg-brand-blue text-white hover:bg-brand-navy');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
