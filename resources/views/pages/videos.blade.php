{{--
    The Videos page: /videos (route name videos), in the main menu. Every active video of the admin's Home videos list (or the standard
    list in config/homepage.php while none have been added), in their order, as players that do not start by themselves. Each shows the
    frame one second in, with its caption and its link. Not shown to search engines while STORE_NOINDEX is true, like every page.
--}}
@php
    $videos = app(\App\Domain\Catalogue\Home\HomeVideos::class)->active();
@endphp

<x-layouts.storefront title="Videos" description="Short films on safety footwear and the features that matter on site, from toe caps and midsoles to slip resistance.">
    <x-ui.section-heading title="Videos" tag="h1" :wide="true">{{ config('homepage.video_section.intro') }}</x-ui.section-heading>

    @if ($videos === [])
        <p class="mt-8 rounded border border-border bg-surface-muted p-6 text-ink-muted" data-videos-empty>There are no videos to show just now.</p>
    @else
        <ul class="mt-8 grid grid-cols-1 gap-8 md:grid-cols-2 xl:grid-cols-3" data-videos-list>
            @foreach ($videos as $video)
                @php
                    $caption = filled($video['caption'] ?? null) ? $video['caption'] : $video['label'];
                    $link = $video['link'] ?? null;
                @endphp

                <li data-video-item>
                    <figure>
                        <video
                            class="block aspect-video w-full bg-brand-navy object-contain"
                            playsinline
                            controls
                            controlslist="nodownload"
                            preload="metadata"
                            aria-label="{{ $video['label'] }}"
                            data-video-label="{{ $video['label'] }}"
                        >
                            <source src="{{ $video['src'] }}" type="{{ str_contains(strtolower(strtok((string) $video['src'], '#')), '.webm') ? 'video/webm' : 'video/mp4' }}">
                            Your browser does not support embedded video.
                        </video>

                        <figcaption class="mt-3 flex flex-wrap items-center justify-between gap-x-6 gap-y-2" data-video-caption>
                            <span class="text-ink-muted">{{ $caption }}</span>

                            @if (is_array($link) && filled($link['route'] ?? null))
                                <a href="{{ route($link['route']) }}" class="font-semibold text-brand-blue hover:underline" data-video-link>{{ $link['label'] ?? 'Find out more' }} &rarr;</a>
                            @endif
                        </figcaption>
                    </figure>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.storefront>
