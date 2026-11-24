{{--
    About page, in the storefront layout.
    Route: /about (route name about), see routes/web.php.

    Copy is supplied by the client (Frontline Safety Gear). The one change to it: the dash in the
    first sentence is a colon here, because this project's copy never uses em dashes.

    Layout: the page heading, a second heading, then two columns from the lg breakpoint (the photo on the left at 45%, the three
    paragraphs on the right at 55%, all in the standard text size), then a rule, the tagline across the full width of the
    container, and the button.
--}}
<x-layouts.storefront title="About us" description="Frontline supplies reliable safety gear to the people who keep the UK moving, through a nationwide distribution network with robust stockholding and rigorous quality control.">
    <article class="w-full" data-about>
        <x-ui.section-heading title="About Frontline" tag="h1" />

        <h2 class="mt-10 text-2xl text-ink sm:text-3xl" data-about-subheading>Your Head to Toe PPE Partner - Frontline Safety Gear</h2>

        {{-- The photo column is 45% of the width and the copy column 55% (the photo was 50%). --}}
        <div class="mt-6 grid items-start gap-8 lg:grid-cols-[minmax(0,45fr)_minmax(0,55fr)] lg:gap-12" data-about-columns>
            <img
                src="{{ asset('images/about/frontline-team.jpg') }}"
                alt="Two colleagues smiling in a warehouse aisle between tall shelves of boxed stock, one holding a tablet and the other, in a high-visibility vest, holding a handheld scanner."
                width="2000"
                height="877"
                decoding="async"
                class="h-auto w-full rounded-lg"
                data-about-image
            >

            <div class="space-y-4 leading-relaxed text-ink" data-about-copy>
                <p>At Frontline, we draw inspiration from the people who matter most: the everyday heroes keeping the world moving. From healthcare professionals and paramedics to police officers, couriers, and hospitality staff, one truth stands out: reliable safety gear is essential.</p>
                <p>In response, we&rsquo;ve built a nationwide distribution network with robust stockholding and rigorous quality control. Every product we supply is carefully selected to meet the highest safety standards, ensuring frontline workers have the protection they need, exactly when they need it.</p>
                <p>Expanding beyond essential gear, we now also offer a lifestyle range that blends safety, comfort, and practicality for everyday use.</p>
            </div>
        </div>

        <hr class="mt-12 border-0 border-t border-border" data-about-rule>

        <section class="pt-10" aria-label="Contact us">
            <p class="w-full text-xl font-semibold text-brand-blue sm:text-2xl" data-about-tagline>Frontline: Innovative safety gear, designed for those who keep the UK moving.</p>

            <x-ui.button class="mt-6" :href="route('contact')">Contact us</x-ui.button>
        </section>
    </article>
</x-layouts.storefront>
