{{-- The home page. Search engines are kept out site-wide by STORE_NOINDEX. --}}
<x-layouts.storefront
    title="Safety footwear for UK businesses"
    description="Safety footwear for UK businesses. S1P, S3 and ESD trainers, shoes and boots. All prices exclude VAT."
    :full="true"
>
    <x-home.hero />

    {{-- Industries slider, straight under the hero. --}}
    <div class="pt-12 sm:pt-16" data-home-industries>
        <x-ui.container>
            <x-ui.section-heading title="Solutions for every workplace" :divider="false" :center="true" />

            <h3 class="mx-auto mt-4 max-w-3xl text-center text-base font-normal leading-relaxed tracking-normal text-ink-muted" data-industries-intro>Streamline your site procurement with a single, dependable UK trade supplier. We stock certified head-to-toe PPE, workwear, and safety footwear ready for rapid nationwide dispatch and competitive bulk pricing.</h3>
        </x-ui.container>

        <div class="mt-8">
            <x-industry-slider />
        </div>
    </div>

    <x-home.trust-strip />

    <x-home.category-tiles />

    <x-home.featured-products />

    <x-home.service-strip />
</x-layouts.storefront>
