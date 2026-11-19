{{--
    Video across the full width of the page container, above the featured products. One of the videos in
    config/homepage.php, picked at random on every page load (the page is never cached, so a refresh picks
    again). Muted so it may autoplay; the controls let a visitor pause it.
--}}
@php
    $videos = config('homepage.videos', []);
    $video = $videos === [] ? null : $videos[array_rand($videos)];
@endphp

@if ($video)
    <section aria-label="{{ $video['label'] }}" class="pt-12 sm:pt-16" data-home-video>
        <x-ui.container>
            <video
                class="block h-auto w-full"
                autoplay
                muted
                playsinline
                controls
                controlslist="nodownload"
                @if (! empty($video['loop'])) loop @endif
                @if (! empty($video['poster'])) poster="{{ $video['poster'] }}" @endif
                preload="{{ $video['preload'] ?? 'metadata' }}"
                aria-label="{{ $video['label'] }}"
                data-video-label="{{ $video['label'] }}"
            >
                <source src="{{ $video['src'] }}" type="video/mp4">
                Your browser does not support embedded video.
            </video>
        </x-ui.container>
    </section>
@endif
