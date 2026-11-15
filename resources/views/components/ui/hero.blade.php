@props(['image' => null, 'imageAlt' => '', 'imageWidth' => null, 'imageHeight' => null])

{{--
    A brand-navy band with white text, and an optional background image. The
    image sits under a brand-navy overlay (darker on the text side) so white
    text keeps at least 4.5:1 contrast whatever the photo shows.
--}}
<section {{ $attributes->class('surface-dark relative isolate overflow-hidden bg-brand-navy text-white') }}>
    @if ($image)
        <img
            src="{{ $image }}"
            alt="{{ $imageAlt }}"
            @if ($imageWidth) width="{{ $imageWidth }}" @endif
            @if ($imageHeight) height="{{ $imageHeight }}" @endif
            fetchpriority="high"
            class="absolute inset-0 -z-20 h-full w-full object-cover object-center"
        >
        <div aria-hidden="true" data-hero-overlay class="absolute inset-0 -z-10 bg-linear-to-r from-brand-navy/90 via-brand-navy/80 to-brand-navy/65"></div>
    @endif

    <x-ui.container class="py-16 sm:py-24">
        {{ $slot }}
    </x-ui.container>
</section>
