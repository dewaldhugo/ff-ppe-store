@props(['href' => null, 'type' => 'button', 'onDark' => false, 'shape' => 'pill'])

{{-- Secondary button: a pill outline with a brand-teal border. Brand-blue text on light surfaces, white on dark ones. --}}
@php
    $classes = 'inline-flex items-center justify-center '.($shape === 'md' ? 'rounded-md' : 'rounded-full').' border-2 border-brand-teal px-6 py-2.5 text-base font-semibold transition-colors '
        .($onDark ? 'text-white hover:bg-white hover:text-brand-navy' : 'text-brand-blue hover:bg-surface-muted');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
