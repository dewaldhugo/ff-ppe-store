@php
    $image = app(\App\Domain\Catalogue\Home\HeroImage::class)->get();
@endphp

{{--
    The home hero: plain brand-navy, text first. The product photo is a second
    column from md up, and only exists while resources/images/hero/hero.webp does.
    Without it the text spans the full width, with no placeholder.
--}}
<section class="surface-dark relative isolate flex items-center overflow-hidden bg-brand-navy text-white sm:min-h-[33rem]" aria-labelledby="home-hero-title" data-home-hero>
    {{-- Decorative site photo, shifted down from centre (position 15%), under a brand-navy overlay that keeps white text readable. --}}
    <img
        src="{{ asset('images/home-hero.jpg') }}"
        alt=""
        width="1536"
        height="1024"
        fetchpriority="high"
        class="absolute inset-0 -z-20 h-full w-full object-cover object-[center_15%]"
    >
    <div aria-hidden="true" data-hero-overlay class="absolute inset-0 -z-10 bg-linear-to-r from-brand-navy/86 via-brand-navy/77 to-brand-navy/62"></div>

    <x-ui.container class="w-full py-14 sm:py-20">
        <div @class(['items-center gap-12', 'md:grid md:grid-cols-2' => $image])>
            <div data-hero-text class="sm:translate-y-[3.3rem]">
                <x-ui.eyebrow class="text-brand-teal">UK TRADE PPE SUPPLIER</x-ui.eyebrow>
                <h1 id="home-hero-title" class="mt-3 text-[2.0625rem] text-white sm:text-[2.475rem] lg:text-[2.75rem]">When safety slips, everyone pays.<br>Quality safety gear isn&rsquo;t optional, it&rsquo;s essential.</h1>
                <p class="mt-4 max-w-2xl text-[1.2375rem] text-white/90">EN-compliant safety footwear, head, eye, and hearing protection ready for immediate dispatch. Bulk trade pricing available.</p>

                <div class="mt-8 flex flex-wrap gap-4">
                    <x-ui.button variant="accent" :href="route('shop')">View all products</x-ui.button>
                    <x-ui.button-outline :href="route('account')" :on-dark="true" shape="md">Open a trade account</x-ui.button-outline>
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
