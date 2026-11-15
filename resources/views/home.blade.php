{{-- The home page. Search engines are kept out site-wide by STORE_NOINDEX. --}}
<x-layouts.storefront
    title="Personal protective equipment for business"
    description="Personal protective equipment for business customers. All prices exclude VAT."
    :full="true"
>
    {{-- The photo is decoration (its sign is not content), so it has no alt text. --}}
    <x-ui.hero :image="asset('images/home-hero.jpg')" :image-width="1529" :image-height="750">
        <x-ui.eyebrow class="text-brand-teal">Trade supplier</x-ui.eyebrow>
        <h1 class="mt-3 max-w-3xl text-4xl text-white sm:text-5xl">Personal protective equipment for business</h1>
        <p class="mt-4 max-w-xl text-lg text-white/90">Content coming soon.</p>

        <div class="mt-8">
            <x-ui.button-outline :href="route('products')" :on-dark="true">Shop products</x-ui.button-outline>
        </div>
    </x-ui.hero>

    <section class="py-12 sm:py-16" aria-labelledby="home-categories">
        <x-ui.container>
            <x-ui.section-heading id="home-categories" eyebrow="Products" title="Shop by category" />

            <x-storefront.category-grid class="mt-8" />
        </x-ui.container>
    </section>
</x-layouts.storefront>
