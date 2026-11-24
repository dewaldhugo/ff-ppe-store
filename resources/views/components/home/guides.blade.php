@php
    // Cards from config/homepage.php. An item whose route does not exist, or with no title, is skipped; with none left the section is not shown.
    $heading = (string) config('homepage.guides.title', '');
    $intro = (string) config('homepage.guides.intro', '');
    // Heroicons (MIT) style outline paths, by the `icon` name in the config: a ruler for sizes, a shield with a tick for features and standards.
    $icons = [
        'ruler' => ['M3.75 7.5h16.5a.75.75 0 0 1 .75.75v7.5a.75.75 0 0 1-.75.75H3.75a.75.75 0 0 1-.75-.75v-7.5a.75.75 0 0 1 .75-.75Z', 'M7.5 7.5v3M12 7.5v4.5M16.5 7.5v3'],
        'shield' => ['M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z'],
    ];
    $items = array_values(array_filter(
        (array) config('homepage.guides.items', []),
        fn (mixed $item): bool => is_array($item) && filled($item['title'] ?? null) && filled($item['route'] ?? null) && \Illuminate\Support\Facades\Route::has((string) $item['route']),
    ));
@endphp

{{-- Buying guides: links to the size guide and the technical features page, between the video and the testimonials. --}}
@if ($items !== [])
    <section class="py-12 sm:py-16" aria-labelledby="home-guides" data-home-guides>
        <x-ui.container>
            <x-ui.section-heading id="home-guides" :title="$heading">{{ $intro }}</x-ui.section-heading>

            <ul class="mt-8 grid gap-6 sm:grid-cols-2">
                @foreach ($items as $item)
                    @php
                        // A photo behind the card, under a dark overlay, when the item has one; otherwise a plain white card.
                        $image = is_array($item['image'] ?? null) && filled($item['image']['file'] ?? null) ? $item['image'] : null;
                    @endphp
                    <li class="flex" data-home-guide>
                        <div @class([
                            'group relative isolate flex w-full flex-col overflow-hidden rounded-lg border p-6 transition-colors hover:border-brand-teal sm:p-8',
                            'surface-dark min-h-[16rem] border-brand-navy bg-brand-navy' => $image,
                            'border-border bg-white' => ! $image,
                        ])>
                            @if ($image)
                                {{-- The photo and the dark overlay sit behind the text (negative z-index inside this card's own stacking context). The overlay is strong enough for white text on the brightest part of either photo. --}}
                                <img
                                    src="{{ asset('images/home/'.$image['file']) }}"
                                    alt=""
                                    width="{{ $image['width'] ?? 1000 }}"
                                    height="{{ $image['height'] ?? 459 }}"
                                    loading="lazy"
                                    decoding="async"
                                    class="absolute inset-0 -z-10 h-full w-full object-cover transition-transform duration-500 motion-safe:group-hover:scale-105"
                                    data-guide-image
                                >
                                <span class="absolute inset-0 -z-10 bg-brand-navy/75" aria-hidden="true" data-guide-overlay></span>
                            @endif

                            {{-- The icon sits beside the heading; it is decoration, the heading says what the card is. --}}
                            <div class="flex items-center gap-3">
                                @if (isset($icons[$item['icon'] ?? '']))
                                    <span @class(['flex size-11 shrink-0 items-center justify-center rounded-full', 'bg-white/15 text-white' => $image, 'bg-surface-muted text-brand-blue' => ! $image]) data-guide-icon>
                                        <svg class="size-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            @foreach ($icons[$item['icon']] as $path)
                                                <path d="{{ $path }}"/>
                                            @endforeach
                                        </svg>
                                    </span>
                                @endif
                                <h3 @class(['text-xl font-bold', 'text-white' => $image, 'text-ink' => ! $image])>{{ $item['title'] }}</h3>
                            </div>
                            <p @class(['mt-2 flex-1', 'text-white' => $image, 'text-ink-muted' => ! $image])>{{ $item['text'] ?? '' }}</p>
                            {{-- The whole card is the link: its stretched ::after covers it. --}}
                            <a href="{{ route($item['route']) }}" @class(['mt-4 inline-flex items-center gap-2 font-semibold after:absolute after:inset-0 hover:underline', 'text-white' => $image, 'text-brand-blue' => ! $image])>
                                {{ $item['link'] ?? $item['title'] }}
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
@endif
