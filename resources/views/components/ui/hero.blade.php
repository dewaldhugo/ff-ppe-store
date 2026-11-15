@props(['image' => null, 'imageAlt' => ''])

{{-- A brand-navy band with white text, and an optional background image. --}}
<section {{ $attributes->class('surface-dark relative isolate overflow-hidden bg-brand-navy text-white') }}>
    @if ($image)
        <img src="{{ $image }}" alt="{{ $imageAlt }}" class="absolute inset-0 -z-10 h-full w-full object-cover opacity-30">
    @endif

    <x-ui.container class="py-16 sm:py-24">
        {{ $slot }}
    </x-ui.container>
</section>
