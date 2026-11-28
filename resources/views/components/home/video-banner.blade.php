{{--
    The video section under the industries slider: a heading and a line of intro, then two videos side by side (one
    above the other on a phone), each with a caption and a link (a few words about the clip and where to go next).
    The videos in config/homepage.php are shuffled on every page load (the page is never cached, so a refresh
    shuffles again) and shown `homepage.video_section.count` (2) at a time; the arrows beside the heading page through
    them, round and round, by sliding the next pair in from the side. Only the first pair has its file address from the start: the others get theirs when their
    page is first opened, so the page does not fetch nine videos. Every player has the same shape (16:9, black bars if
    the clip is another shape) so the two never differ in height, and shows the frame one second in (`#t=1`) until
    played. Nothing plays by itself, and the sound is not muted. The arrows need JavaScript (Alpine) and stay hidden
    without it, when the first pair is all there is. Not shown when the list is empty.
--}}
@php
    $section = (array) config('homepage.video_section', []);
    $videos = array_values((array) config('homepage.videos', []));
    $perPage = max(1, min((int) ($section['count'] ?? 2), count($videos)));
    shuffle($videos);
    $pageCount = $videos === [] ? 0 : (int) ceil(count($videos) / $perPage);
    // Round the list, so the last page is full too (it takes its first clips from the start of the list).
    $pages = [];
    foreach ($videos === [] ? [] : range(0, $pageCount - 1) as $p) {
        foreach (range(0, $perPage - 1) as $k) {
            $pages[$p][] = $videos[($p * $perPage + $k) % count($videos)];
        }
    }
@endphp

@if ($videos !== [])
    <section
        aria-labelledby="home-video"
        class="pt-12 sm:pt-16"
        data-home-video
        x-data="{
            page: 0,
            count: {{ $pageCount }},
            show(i) {
                this.$root.querySelectorAll('video').forEach((v) => v.pause());
                this.page = (i + this.count) % this.count;
                this.$refs['page' + this.page].querySelectorAll('source[data-src]').forEach((s) => {
                    s.src = s.dataset.src;
                    s.removeAttribute('data-src');
                    s.parentElement.load();
                });
            },
        }"
    >
        <x-ui.container>
            <div class="relative">
                <x-ui.section-heading id="home-video" :wide="true" :title="$section['title'] ?? 'Watch'">{{ $section['intro'] ?? '' }}</x-ui.section-heading>

                @if ($pageCount > 1)
                    {{-- Beside the heading, at the right: previous, where you are, next. --}}
                    <div class="absolute right-0 top-0 flex items-center gap-3" x-cloak data-video-pager>
                        <button type="button" class="flex size-10 items-center justify-center rounded-full border border-border-strong bg-white text-ink transition-colors hover:border-brand-teal hover:bg-brand-teal-light focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-blue" aria-label="Previous videos" x-on:click="show(page - 1)" data-video-prev>
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                        </button>

                        <span class="min-w-12 text-center text-sm text-ink-muted" aria-live="polite" data-video-position><span x-text="page + 1">1</span> / {{ $pageCount }}</span>

                        <button type="button" class="flex size-10 items-center justify-center rounded-full border border-border-strong bg-white text-ink transition-colors hover:border-brand-teal hover:bg-brand-teal-light focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-blue" aria-label="Next videos" x-on:click="show(page + 1)" data-video-next>
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                        </button>
                    </div>
                @endif
            </div>

            {{-- A row of pages, each as wide as the section; the arrows slide the row so the next pair comes in from the side. The pages out of sight are inert, so a keyboard never lands in them. --}}
            <div class="mt-8 overflow-hidden" data-video-viewport>
            <div class="flex transition-transform duration-500 ease-in-out" x-bind:style="'transform: translateX(-' + (page * 100) + '%)'" data-video-track>
            @foreach ($pages as $p => $pageVideos)
                <div
                    @class(['grid w-full shrink-0 grid-cols-1 gap-8', 'md:grid-cols-2' => count($pageVideos) > 1])
                    @if ($p > 0) inert @endif
                    x-ref="page{{ $p }}"
                    x-bind:inert="page !== {{ $p }}"
                    data-video-page
                >
                    @foreach ($pageVideos as $video)
                        @php
                            $caption = filled($video['caption'] ?? null) ? $video['caption'] : ($video['label'] ?? '');
                            $link = $video['link'] ?? null;
                            // The frame one second in is the picture shown before play (there is no poster image).
                            $src = str_contains($video['src'], '#') ? $video['src'] : $video['src'].'#t=1';
                        @endphp

                        <figure data-video-item>
                            <video
                                class="block aspect-video w-full bg-brand-navy object-contain"
                                playsinline
                                controls
                                controlslist="nodownload"
                                @if (! empty($video['loop'])) loop @endif
                                preload="{{ $p === 0 ? ($video['preload'] ?? 'metadata') : 'none' }}"
                                aria-label="{{ $video['label'] }}"
                                data-video-label="{{ $video['label'] }}"
                            >
                                <source @if ($p === 0) src="{{ $src }}" @else data-src="{{ $src }}" @endif type="video/mp4">
                                Your browser does not support embedded video.
                            </video>

                            <figcaption class="mt-3 flex flex-wrap items-center justify-between gap-x-6 gap-y-2" data-video-caption>
                                <span class="text-ink-muted">{{ $caption }}</span>

                                @if (is_array($link) && filled($link['route'] ?? null))
                                    <a href="{{ route($link['route']) }}" class="font-semibold text-brand-blue hover:underline" data-video-link>{{ $link['label'] ?? 'Find out more' }} &rarr;</a>
                                @endif
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            @endforeach
            </div>
            </div>
        </x-ui.container>
    </section>
@endif
