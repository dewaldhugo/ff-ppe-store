{{-- The Product Spotlight: one campaign image, picked at random on each page load from the set below. Served from public/images/home. --}}
@php
    $spotlights = [
        [
            'file' => 'cador-colours-that-work',
            'webp' => true,
            'alt' => 'CADOR S1 P safety trainer in yellow, green, blue, pink, red and grey, with its AirBlaze ventilation, SRC slip resistance, ESD, SJ Foam footbed and steel toe cap and midsole.',
            'width' => 1200,
            'height' => 1563,
        ],
        [
            'file' => 'topskate-s3s-safety-sneaker',
            'webp' => false,
            'alt' => 'TOPSKATE S3S, the safety sneaker you can lean on, in light grey, dark grey and blue.',
            'width' => 1200,
            'height' => 1518,
        ],
        [
            'file' => 'maximus-helmet',
            'webp' => false,
            'alt' => 'MAXIMUS safety helmet with ERT Energy Reduction Technology, in yellow, white and blue.',
            'width' => 1200,
            'height' => 1508,
        ],
    ];
    $spotlight = $spotlights[array_rand($spotlights)];
@endphp
<section class="py-12 sm:py-16" aria-labelledby="home-featured" data-home-featured>
    <x-ui.container>
        <x-ui.section-heading id="home-featured" title="Product Spotlight" />

        <figure class="mt-8">
            <picture>
                @if ($spotlight['webp'])
                    <source type="image/webp" srcset="{{ asset('images/home/'.$spotlight['file'].'.webp') }}">
                @endif
                <img
                    src="{{ asset('images/home/'.$spotlight['file'].'.jpg') }}"
                    alt="{{ $spotlight['alt'] }}"
                    width="{{ $spotlight['width'] }}"
                    height="{{ $spotlight['height'] }}"
                    loading="lazy"
                    decoding="async"
                    class="h-auto w-full rounded-lg"
                    data-featured-image
                >
            </picture>
        </figure>
    </x-ui.container>
</section>
