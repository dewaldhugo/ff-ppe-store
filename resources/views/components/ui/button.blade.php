@props(['href' => null, 'type' => 'button'])

{{-- Primary button: brand-blue with white text. A link when it has an href. --}}
@php
    $classes = 'inline-flex items-center justify-center rounded-md bg-brand-blue px-6 py-3 text-base font-semibold text-white transition-colors hover:bg-brand-navy disabled:cursor-not-allowed disabled:bg-disabled-surface disabled:text-disabled-ink';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
