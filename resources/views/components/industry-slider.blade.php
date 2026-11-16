{{--
    Industry tiles slider. Place at resources/views/components/industry-slider.blade.php
    Usage: <x-industry-slider />

    Images are expected at resources/images/industries/{slug}.webp and {slug}.jpg
    and must be registered with Vite (see import.meta.glob note in the hand-over).
--}}
@php
    $industries = [
        'production'   => 'Industry',
        'logistics'    => 'Logistics',
        'construction' => 'Construction',
        'medical'      => 'Medical',
        'uniform'      => 'Uniform',
        'food'         => 'Food & beverages',
        'catering'     => 'Catering',
        'tactical'     => 'Tactical',
        'assembly'     => 'Assembly',
        'automotive'   => 'Automotive',
        'chemical'     => 'Chemical',
        'cleaning'     => 'Cleaning',
        'mining'       => 'Mining',
        'oilandgas'    => 'Oil & Gas',
    ];

    $tiles = collect($industries)->map(fn ($label, $slug) => [
        'label' => $label,
        'webp'  => Vite::asset("resources/images/industries/{$slug}.webp"),
        'jpg'   => Vite::asset("resources/images/industries/{$slug}.jpg"),
    ])->values()->all();
@endphp

<section
    aria-label="Industries we supply"
    class="w-full"
    x-data="{
        items: @js($tiles),
        perView: 10,
        index: 0,
        animate: true,
        timer: null,
        paused: false,

        get slides() {
            // Clones of the first 10 tiles let the loop run past the end without a visible jump.
            return this.items.concat(this.items.slice(0, 10));
        },
        calc() {
            const w = window.innerWidth;
            this.perView = w <= 575 ? 1 : w <= 767 ? 2 : w <= 1000 ? 3 : w <= 1199 ? 4 : w <= 1800 ? 6 : w <= 2000 ? 8 : 10;
        },
        init() {
            this.calc();
            this.timer = setInterval(() => {
                if (this.paused || document.hidden) return;
                this.animate = true;
                this.index++;
            }, 1900);
        },
        settle() {
            // After sliding onto the clones, jump back to the real start with no transition.
            if (this.index >= this.items.length) {
                this.animate = false;
                this.index = 0;
            }
        },
        destroy() { clearInterval(this.timer); },
    }"
    x-on:resize.window.debounce.150ms="calc()"
    x-on:mouseenter="paused = true"
    x-on:mouseleave="paused = false"
    x-on:focusin="paused = true"
    x-on:focusout="paused = false"
>
    <div class="overflow-hidden">
        <ul
            class="flex"
            :style="{
                transform: 'translateX(-' + (index * 100 / perView) + '%)',
                transition: animate ? 'transform 400ms ease' : 'none',
            }"
            x-on:transitionend.self="settle()"
        >
            <template x-for="(tile, i) in slides" :key="i">
                <li
                    class="shrink-0"
                    style="width: 10%"
                    :style="{ width: (100 / perView) + '%' }"
                    :aria-hidden="i >= items.length ? 'true' : null"
                >
                    <div class="h-[205px] bg-surface-muted">
                        <picture>
                            <source type="image/webp" :srcset="tile.webp">
                            <img
                                :src="tile.jpg"
                                alt=""
                                loading="lazy"
                                decoding="async"
                                class="h-[205px] w-full object-cover object-bottom"
                            >
                        </picture>
                    </div>
                    <p
                        class="border-r border-white/20 bg-brand-blue px-2 py-2 text-center text-sm font-bold uppercase text-white"
                        x-text="tile.label"
                    ></p>
                </li>
            </template>
        </ul>
    </div>
</section>