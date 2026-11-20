{{--
    About page, in the storefront layout.
    Route: /about (route name about), see routes/web.php.

    Copy is supplied by the client (Frontline Safety Gear). The one change to it: the dash in the
    first sentence is a colon here, because this project's copy never uses em dashes.
--}}
<x-layouts.storefront title="About us" description="Frontline supplies reliable safety gear to the people who keep the UK moving, through a nationwide distribution network with robust stockholding and rigorous quality control.">
    <article class="w-full" data-about>
        <x-ui.section-heading title="About Frontline" tag="h1" />

        <img
            src="{{ asset('images/about/frontline-team.jpg') }}"
            alt="Two colleagues smiling in a warehouse aisle between tall shelves of boxed stock, one holding a tablet and the other, in a high-visibility vest, holding a handheld scanner."
            width="2000"
            height="877"
            decoding="async"
            class="mt-8 h-auto w-full rounded-lg"
            data-about-image
        >

        <div class="mt-10 max-w-3xl space-y-4 leading-relaxed text-ink" data-about-copy>
            <p class="text-lg">At Frontline, we draw inspiration from the people who matter most: the everyday heroes keeping the world moving. From healthcare professionals and paramedics to police officers, couriers, and hospitality staff, one truth stands out: reliable safety gear is essential.</p>
            <p>In response, we&rsquo;ve built a nationwide distribution network with robust stockholding and rigorous quality control. Every product we supply is carefully selected to meet the highest safety standards, ensuring frontline workers have the protection they need, exactly when they need it.</p>
            <p>Expanding beyond essential gear, we now also offer a lifestyle range that blends safety, comfort, and practicality for everyday use.</p>
        </div>

        <section class="mt-12 border-t border-border pt-10" aria-label="Contact us">
            <p class="max-w-3xl text-xl font-semibold text-brand-blue sm:text-2xl" data-about-tagline>Frontline: Innovative safety gear, designed for those who keep the UK moving.</p>

            <x-ui.button class="mt-6" :href="route('contact')">Contact us</x-ui.button>
        </section>
    </article>
</x-layouts.storefront>
