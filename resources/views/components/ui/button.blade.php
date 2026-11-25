@props(['href' => null, 'type' => 'button', 'variant' => 'primary'])

{{--
    Primary button: a brand-teal fill with brand-navy text (7.98:1; white on teal fails AA), turning navy with
    white text on hover. The accent variant is the same fill but turns white on hover, for use on navy surfaces.
    A link when it has an href.
--}}
@php
    $classes = 'inline-flex items-center justify-center rounded-md px-6 py-3 text-base font-semibold transition-colors disabled:cursor-not-allowed disabled:bg-disabled-surface disabled:text-disabled-ink '
        .($variant === 'accent'
            ? 'bg-brand-teal text-brand-navy hover:bg-white'
            : 'bg-brand-teal text-brand-navy hover:bg-brand-navy hover:text-white');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
