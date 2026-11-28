@props(['name'])

{{-- The small outline icon of a Tools link (the header beside the search, and the mobile menu): a ruler for the size guide, sliders for the technical features. --}}
<svg aria-hidden="true" {{ $attributes->class('h-5 w-5 shrink-0') }} fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" data-tool-icon="{{ $name }}">
    @if ($name === 'size-guide')
        <path d="M3 17.5 17.5 3 21 6.5 6.5 21 3 17.5Z"/>
        <path d="m7.5 13.5 2 2M10.5 10.5l2 2M13.5 7.5l2 2"/>
    @else
        <path d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/>
    @endif
</svg>
