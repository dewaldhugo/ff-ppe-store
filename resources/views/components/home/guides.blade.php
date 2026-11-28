@php
    // Cards from config/homepage.php. An item whose route does not exist, or with no title, is skipped; with none left the section is not shown.
    $heading = (string) config('homepage.guides.title', '');
    $intro = (string) config('homepage.guides.intro', '');
    // The outline paths, by the `icon` name in the config: a ruler for sizes, a shield with a tick for features and standards. The same icons
    // are the Tools links' beside the header's search box (App\Support\GuideIcons).
    $icons = \App\Support\GuideIcons::PATHS;
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
                        // A photo behind the card, under a dark overlay, when the item has one (`image`); a picture beside the words, on a white
                        // panel (`panel`: a diagram, which a photo background and its overlay would spoil); otherwise a plain white card.
                        $image = is_array($item['image'] ?? null) && filled($item['image']['file'] ?? null) ? $item['image'] : null;
                        $panel = $image === null && is_array($item['panel'] ?? null) && filled($item['panel']['file'] ?? null) ? $item['panel'] : null;
                        // A dark charcoal card with white words: one with a photo, or one with a panel.
                        $dark = $image !== null || $panel !== null;
                        // A panel has a white margin round its picture (a diagram on white), unless the item says `padded` => false (a photo, edge to edge).
                        $padded = (bool) ($panel['padded'] ?? true);
                    @endphp
                    <li class="flex" data-home-guide>
                        <div @class([
                            'group relative isolate flex w-full flex-col overflow-hidden rounded-lg border p-6 transition-colors hover:border-brand-teal-bright sm:p-8',
                            'lg:flex-row lg:items-end lg:gap-8' => $panel,
                            'surface-dark min-h-[14rem] border-brand-charcoal bg-brand-charcoal' => $dark,
                            'border-border bg-white' => ! $dark,
                        ])>
                            @if ($image)
                                {{-- The photo and the charcoal overlay sit behind the text (negative z-index inside this card's own stacking context). The words run the full width of the card, and the size guide photo is bright (its sky shows through the scaffolding everywhere: measured, the brightest 1% of pixels in every tenth of its width need an overlay of 62% or more for white text to keep 4.5:1). So the overlay never drops below 62% charcoal: an even 70% below lg, and from lg a gradient from 80% on the left to 62% on the right. The photo's address carries its file's modified time, so a replaced photo is fetched again by a browser that kept the old one. --}}
                                <img
                                    src="{{ asset('images/home/'.$image['file']).((($stamp = @filemtime(public_path('images/home/'.$image['file']))) !== false) ? '?v='.$stamp : '') }}"
                                    alt=""
                                    width="{{ $image['width'] ?? 1000 }}"
                                    height="{{ $image['height'] ?? 459 }}"
                                    loading="lazy"
                                    decoding="async"
                                    class="absolute inset-0 -z-10 h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                                    data-guide-image
                                >
                                <span class="absolute inset-0 -z-10 bg-brand-charcoal/70 lg:bg-transparent lg:bg-linear-to-r lg:from-brand-charcoal/80 lg:to-brand-charcoal/62" aria-hidden="true" data-guide-overlay></span>
                            @endif

                            <div @class(['flex w-full flex-1 flex-col', 'lg:basis-1/2' => $panel]) data-guide-words>
                            {{-- The icon sits beside the heading; it is decoration, the heading says what the card is. --}}
                            <div class="flex items-center gap-3">
                                @if (isset($icons[$item['icon'] ?? '']))
                                    {{-- A teal chip with a navy icon (the brand colours: 6:1), on the photo or on the plain card. --}}
                                    <span @class(['flex size-11 shrink-0 items-center justify-center rounded-full', 'bg-brand-teal-bright text-brand-navy' => $dark, 'bg-brand-teal-light text-brand-navy' => ! $dark]) data-guide-icon>
                                        <svg class="size-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            @foreach ($icons[$item['icon']] as $path)
                                                <path d="{{ $path }}"/>
                                            @endforeach
                                        </svg>
                                    </span>
                                @endif
                                <h3 @class(['text-xl font-bold', 'text-white' => $dark, 'text-ink' => ! $dark])>{{ $item['title'] }}</h3>
                            </div>
                            <p @class(['mt-2 flex-1 text-[0.81rem]', 'text-white' => $dark, 'text-ink-muted' => ! $dark])>{{ $item['text'] ?? '' }}</p>
                            {{-- The whole card is the link: its stretched ::after covers it. It looks like the site's buttons: teal with navy words, white on hover (light teal on the plain card). --}}
                            <a href="{{ route($item['route']) }}" @class(['mt-5 inline-flex w-fit items-center gap-2 rounded-md px-5 py-2.5 font-semibold transition-colors after:absolute after:inset-0', 'bg-brand-teal-bright text-brand-navy hover:bg-white' => $dark, 'bg-brand-teal text-white hover:bg-brand-navy' => ! $dark])>
                                {{ $item['link'] ?? $item['title'] }}
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                            </a>
                            </div>

                            @if ($panel)
                                {{-- The picture on a white panel beside the words (below them on a phone). The row is items-end, so the panel's bottom edge is level with the bottom of the button. A picture, so it is shown whole (never cropped) and not under an overlay. Its address carries the file's modified time, like the photos. --}}
                                <div @class(['mt-6 flex items-center justify-center overflow-hidden rounded-md bg-white lg:mt-0 lg:basis-1/2', 'p-3' => $padded]) data-guide-panel>
                                    <img
                                        src="{{ asset('images/home/'.$panel['file']).((($stamp = @filemtime(public_path('images/home/'.$panel['file']))) !== false) ? '?v='.$stamp : '') }}"
                                        alt="{{ $panel['alt'] ?? '' }}"
                                        width="{{ $panel['width'] ?? 816 }}"
                                        height="{{ $panel['height'] ?? 550 }}"
                                        loading="lazy"
                                        decoding="async"
                                        class="h-auto w-full"
                                        data-guide-diagram
                                    >
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
@endif
