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
            {{-- The shared heading style, 20% larger (1.5rem to 1.8rem, 1.875rem to 2.25rem). --}}
            <div class="[&_h2]:text-[1.8rem] sm:[&_h2]:text-[2.25rem]">
                <x-ui.section-heading title="Solutions for every workplace" />
            </div>

            <h3 class="mt-4 max-w-4xl text-[1.2rem] font-normal leading-relaxed tracking-normal text-ink-muted" data-industries-intro>Streamline your site procurement with a single, dependable UK trade supplier. We stock certified head-to-toe PPE, workwear, and safety footwear ready for rapid nationwide dispatch and competitive bulk pricing.</h3>
        </x-ui.container>

        <div class="mt-8">
            <x-industry-slider />
        </div>
    </div>

    <x-home.trust-strip />

    <x-home.product-picks />

    <x-home.featured-products />

    <x-home.service-strip />
</x-layouts.storefront>
