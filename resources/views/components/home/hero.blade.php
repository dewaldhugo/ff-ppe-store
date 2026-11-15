@php
    $image = app(\App\Domain\Catalogue\Home\HeroImage::class)->get();
@endphp

{{--
    The home hero: plain brand-navy, text first. The product photo is a second
    column from md up, and only exists while resources/images/hero/hero.webp does.
    Without it the text spans the full width, with no placeholder.
--}}
<section class="surface-dark bg-brand-navy text-white" aria-labelledby="home-hero-title" data-home-hero>
    <x-ui.container class="py-12 sm:py-20">
        <div @class(['items-center gap-12', 'md:grid md:grid-cols-2' => $image])>
            <div data-hero-text>
                <x-ui.eyebrow class="text-brand-teal">Trade supplier</x-ui.eyebrow>
                <h1 id="home-hero-title" class="mt-3 max-w-3xl text-4xl text-white sm:text-5xl">Safety footwear for UK businesses</h1>
                <p class="mt-4 max-w-xl text-lg text-white/90">S1P, S3 and ESD trainers, shoes and boots in a wide range of sizes. All prices shown exclusive of VAT.</p>

                <div class="mt-8 flex flex-wrap gap-4">
                    <x-ui.button variant="accent" :href="route('category', 'safety-footwear')">Shop safety footwear</x-ui.button>
                    <x-ui.button-outline :href="route('contact')" :on-dark="true">Contact us for trade enquiries</x-ui.button-outline>
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
