{{--
    The video section under the industries slider: a heading and a line of intro, then the video across the full
    width of the page container, then a caption and a link (a few words about the clip and where to go next). One
    of the videos in config/homepage.php is picked at random on every page load (the page is never cached, so a
    refresh picks again). Muted so it may autoplay; the controls let a visitor pause it. Not shown when the list
    is empty.
--}}
@php
    $section = (array) config('homepage.video_section', []);
    $videos = config('homepage.videos', []);
    $video = $videos === [] ? null : $videos[array_rand($videos)];
    $caption = filled($video['caption'] ?? null) ? $video['caption'] : ($video['label'] ?? '');
    $link = $video['link'] ?? null;
@endphp

@if ($video)
    <section aria-labelledby="home-video" class="pt-12 sm:pt-16" data-home-video>
        <x-ui.container>
            <x-ui.section-heading id="home-video" :title="$section['title'] ?? 'Watch'">{{ $section['intro'] ?? '' }}</x-ui.section-heading>

            <figure class="mt-8">
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

                <figcaption class="mt-3 flex flex-wrap items-center justify-between gap-x-6 gap-y-2" data-video-caption>
                    <span class="text-ink-muted">{{ $caption }}</span>

                    @if (is_array($link) && filled($link['route'] ?? null))
                        <a href="{{ route($link['route']) }}" class="font-semibold text-brand-blue hover:underline" data-video-link>{{ $link['label'] ?? 'Find out more' }} &rarr;</a>
                    @endif
                </figcaption>
            </figure>
        </x-ui.container>
    </section>
@endif
