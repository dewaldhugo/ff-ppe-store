@php
    $image = app(\App\Domain\Catalogue\Home\HeroImage::class)->get();
@endphp

{{--
    The home hero: plain brand-navy, text first. The product photo is a second
    column from md up, and only exists while resources/images/hero/hero.webp does.
    Without it the text spans the full width, with no placeholder.
--}}
<section class="surface-dark relative isolate flex items-center overflow-hidden bg-brand-navy text-white sm:min-h-[33rem] lg:min-h-[39.6rem]" aria-labelledby="home-hero-title" data-home-hero>
    {{--
        Decorative background photos: the eight hero-bg images in use, starting at a random one on each page
        load and fading into the next every 6 seconds, centred, no overlay. Only the first photo is in
        the page; the others load just before their turn (data-src). The photo being faded in sits on
        top while the previous one stays fully visible beneath it, so the background never dips to
        navy. Without JavaScript it stays on the first photo. It fades whatever the visitor's "reduce
        motion" setting: a slow fade is not the kind of movement that setting is for, and a slideshow that
        silently stops looks broken.

        On a phone (below the sm breakpoint, 640px) there is no slideshow: the first slide is a
        <picture> whose phone source is hero-bg-9, so a phone shows that one photo and never loads
        another. The script only rotates from 640px up, and stops (back on the first slide) if the
        window is narrowed below it.
    --}}
    @php
        // The photos in the slideshow, by number (public/images/hero-bg-N.jpg). 3, 4, 5 and 6 are left out;
        // their files are still in public/images. 11 and 12 are the farm and care home photos.
        $heroImages = [1, 2, 7, 8, 9, 10, 11, 12];
        // The one photo a phone shows.
        $heroMobile = 9;
        $heroCount = count($heroImages);
        $heroStart = random_int(0, $heroCount - 1);
        $heroOrder = array_map(fn (int $step): int => $heroImages[($heroStart + $step) % $heroCount], range(0, $heroCount - 1));
    @endphp
    <div
        class="absolute inset-0 -z-10"
        aria-hidden="true"
        data-hero-slides
        data-interval="6000"
        x-data="{
            current: 0,
            previous: null,
            count: {{ count($heroOrder) }},
            timer: null,
            async load(i) {
                const el = this.$refs['slide' + i];
                if (el.dataset.src) { el.src = el.dataset.src; el.removeAttribute('data-src'); }
                // Never wait more than 4 seconds, so one slow photo cannot stall the rotation.
                try { await Promise.race([el.decode(), new Promise((r) => setTimeout(r, 4000))]); } catch (e) {}
            },
            start() {
                if (this.timer) return;
                this.load(1);
                this.timer = setInterval(async () => {
                    if (document.hidden) return;
                    const next = (this.current + 1) % this.count;
                    await this.load(next);
                    this.previous = this.current;
                    this.current = next;
                    this.load((next + 1) % this.count);
                }, 6000);
            },
            stop() {
                clearInterval(this.timer);
                this.timer = null;
                this.current = 0;
                this.previous = null;
            },
            init() {
                // Rotate from 640px up only. Below it the first slide (hero-bg-9) just stays.
                const wide = window.matchMedia('(min-width: 640px)');
                const sync = () => (wide.matches ? this.start() : this.stop());
                wide.addEventListener('change', sync);
                sync();
            },
        }"
    >
        @foreach ($heroOrder as $i => $n)
            @if ($i === 0)
                {{-- A phone gets hero-bg-9 whatever the random first photo is. --}}
                <picture>
                    <source media="(max-width: 639px)" srcset="{{ asset('images/hero-bg-'.$heroMobile.'.jpg') }}">
            @endif
            <img
                @if ($i === 0) src="{{ asset('images/hero-bg-'.$n.'.jpg') }}" fetchpriority="high" @else data-src="{{ asset('images/hero-bg-'.$n.'.jpg') }}" @endif
                alt=""
                width="1920"
                height="882"
                @if ($i === 0) data-hero-bg @endif
                data-hero-slide
                x-ref="slide{{ $i }}"
                data-state="{{ $i === 0 ? 'current' : 'idle' }}"
                x-bind:data-state="current === {{ $i }} ? 'current' : (previous === {{ $i }} ? 'previous' : 'idle')"
                class="absolute inset-0 h-full w-full object-cover object-center opacity-0 data-[state=current]:z-10 data-[state=current]:opacity-100 data-[state=current]:transition-opacity data-[state=current]:duration-[1500ms] data-[state=current]:ease-in-out data-[state=previous]:opacity-100"
            >
            @if ($i === 0)
                </picture>
            @endif
        @endforeach
    </div>

    <x-ui.container class="w-full py-14 sm:py-20">
        <div @class(['items-center gap-12', 'md:grid md:grid-cols-2' => $image])>
            <div data-hero-text class="sm:translate-y-[3.3rem]">
                <h1 id="home-hero-title" class="text-[1.753rem] text-white sm:text-[2.475rem] lg:text-[2.75rem]">When safety slips, everyone pays.<br>Quality safety gear isn&rsquo;t optional, it&rsquo;s essential.</h1>
                {{-- Not shown on a phone (below the sm breakpoint, 640px): the heading and the buttons are enough there. --}}
                <p class="mt-4 hidden max-w-2xl text-[1.2375rem] text-white/90 sm:block" data-hero-subheading>EN-compliant safety footwear, head, eye, and hearing protection ready for immediate dispatch. Bulk trade pricing available.</p>

                <div class="mt-8 flex flex-wrap gap-4">
                    {{-- The primary (dark grey) button, with a thin light border so it stands out against the dark photos. --}}
                    <x-ui.button :href="route('shop')" class="border border-white/40">View all products</x-ui.button>
                    @guest('web')
                        {{-- Not shown on a phone (below 640px). max-sm:hidden, not hidden + sm:inline-flex: the button's own inline-flex would fight a plain hidden. --}}
                        <x-ui.button-outline :href="route('register')" :on-dark="true" shape="md" class="max-sm:hidden">Open a trade account</x-ui.button-outline>
                    @endguest
                </div>
            </div>

            @if ($image)
                {{-- Decorative: the heading says what the page sells. Fixed size, so no layout shift. --}}
                <div class="hidden md:block" data-hero-image>
                    <img
                        src="{{ $image['url'] }}"
                        alt=""
                        width="{{ $image['width'] }}"
                        height="{{ $image['height'] }}"
                        fetchpriority="high"
                        class="h-auto w-full"
                    >
                </div>
            @endif
        </div>
    </x-ui.container>
</section>
