{{-- The Product Spotlight: one campaign image, CADOR S1 P in six colours. Served from public/images/home. --}}
<section class="py-12 sm:py-16" aria-labelledby="home-featured" data-home-featured>
    <x-ui.container>
        <x-ui.section-heading id="home-featured" title="Product Spotlight" />

        <figure class="mt-8">
            <picture>
                <source type="image/webp" srcset="{{ asset('images/home/cador-colours-that-work.webp') }}">
                <img
                    src="{{ asset('images/home/cador-colours-that-work.jpg') }}"
                    alt="CADOR S1 P safety trainer in yellow, green, blue, pink, red and grey, with its AirBlaze ventilation, SRC slip resistance, ESD, SJ Foam footbed and steel toe cap and midsole."
                    width="1200"
                    height="1563"
                    loading="lazy"
                    decoding="async"
                    class="h-auto w-full rounded-lg"
                    data-featured-image
                >
            </picture>
        </figure>
    </x-ui.container>
</section>
