{{-- Two feature videos. The files are hosted on frontline-footwear.com and their poster images on frontline-shop.com. --}}
@php
    $videos = [
        [
            'src' => 'https://frontline-footwear.com/shop/datasheets/CADOR%20Colours%20that%20work.mp4',
            'poster' => 'https://frontline-shop.com/v1.jpg',
            'label' => 'CADOR: colours that work',
        ],
        [
            'src' => 'https://frontline-footwear.com/shop/datasheets/MODULO_540p.mp4',
            'poster' => 'https://frontline-shop.com/v2.jpg',
            'label' => 'MODULO',
        ],
    ];
@endphp

<section class="py-12 sm:py-16" aria-labelledby="home-featured" data-home-featured>
    <x-ui.container>
        <x-ui.section-heading id="home-featured" title="Featured products" />

        <div class="mt-8 grid gap-6 md:grid-cols-2">
            @foreach ($videos as $video)
                <video
                    class="aspect-video w-full rounded-lg bg-surface-muted object-cover"
                    src="{{ $video['src'] }}"
                    poster="{{ $video['poster'] }}"
                    controls
                    preload="metadata"
                    controlslist="nodownload"
                    aria-label="{{ $video['label'] }}"
                    data-featured-video
                ></video>
            @endforeach
        </div>
    </x-ui.container>
</section>
