{{-- The home page. Search engines are kept out site-wide by STORE_NOINDEX. --}}
{{-- Order: hero (with the trust strip at its bottom), industries, shop by category, PPE Health Check, shop by brand, featured products, spotlight, video, buying guides, testimonials, service strip. --}}
<x-layouts.storefront
    full-title="Frontline Safety Gear"
    description="Streamline your site procurement with a single, dependable UK & EU trade supplier. We stock certified head-to-toe PPE, workwear, and safety footwear ready for rapid nationwide dispatch and competitive bulk pricing."
    :full="true"
>
    <x-home.hero />

    {{-- Industries slider. --}}
    <div class="py-12 sm:py-16" data-home-industries>
        <x-ui.container>
            {{-- The shared heading style, 20% larger (1.5rem to 1.8rem, 1.875rem to 2.25rem). --}}
            <div class="[&_h2]:text-[1.8rem] sm:[&_h2]:text-[2.25rem]">
                <x-ui.section-heading title="Solutions for every workplace" />
            </div>

            {{-- Three paragraphs, not a heading: the lead says who we are, the other two how we serve every industry in the slider below. The copy is a draft for the client to confirm. --}}
            <div class="mt-4 w-full space-y-4 text-[1.2rem] leading-relaxed text-ink-muted" data-industries-intro>
                <p>Streamline your site procurement with a single, dependable UK &amp; EU trade supplier. We stock certified head-to-toe PPE, workwear, and safety footwear ready for rapid nationwide dispatch and competitive bulk pricing.</p>
                <p>Every workplace has different risks, so we cover the full range: S1P and S3 safety footwear to EN ISO 20345, ESD and metal-free options, waterproof boots and slip resistant soles.</p>
                <p>From building sites to kitchens and hospitals, one trade account serves every team. Use our size guide and technical features guide to match the right protection to each role.</p>
            </div>
        </x-ui.container>

        <div class="mt-8">
            <x-industry-slider />
        </div>
    </div>

    {{-- Shop by category: the five top-level categories, as on /shop, each with its picture (App\Domain\Catalogue\Menu\CategoryCardImage). --}}
    <div class="pb-12 sm:pb-16" data-home-categories>
        <x-ui.container>
            <x-ui.section-heading title="Shop by category">Choose a category, or search by name, brand or safety class.</x-ui.section-heading>

            {{-- A search with a category to look in, then the cards, then the way to the page of every category. --}}
            <x-storefront.category-search class="mt-6" />

            <x-storefront.category-grid class="mt-8" />

            <p class="mt-8 text-center" data-browse-all-categories>
                <x-ui.button-outline :href="route('shop')" shape="md">Browse all categories</x-ui.button-outline>
            </p>
        </x-ui.container>
    </div>

    <x-home.health-check />

    <x-home.brand-strip />

    <x-home.product-picks />

    <x-home.featured-products />

    <x-home.video-banner />

    <x-home.guides />

    <x-home.testimonials />

    <x-home.service-strip />
</x-layouts.storefront>
