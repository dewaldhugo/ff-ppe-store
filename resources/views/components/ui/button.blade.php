@props(['href' => null, 'type' => 'button', 'variant' => 'primary'])

{{--
    Primary button: the deep teal (brand-teal) with white text (5.4:1), turning navy on hover. The accent variant is the
    bright teal with navy text (7.6:1) that turns white on hover, for use on navy surfaces. A link when it has an href.
--}}
@php
    $classes = 'inline-flex items-center justify-center rounded-md px-6 py-3 text-base font-semibold transition-colors disabled:cursor-not-allowed disabled:bg-disabled-surface disabled:text-disabled-ink '
        .($variant === 'accent'
            ? 'bg-brand-teal-bright text-brand-navy hover:bg-white'
            : 'bg-brand-teal text-white hover:bg-brand-navy');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
