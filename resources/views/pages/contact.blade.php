{{--
    Contact page, in the storefront layout.
    Route: /contact (route name contact), see routes/web.php.

    Copy is supplied by the client. The enquiry form is the Livewire component
    App\Livewire\Contact\EnquiryForm; it emails the store email in config/store.php
    (or STORE_ENQUIRY_EMAIL when that is set). Left out on purpose: the hero photo and
    the sister-site buttons. The address and phone number are in the footer.
--}}
@php($emailHref = \App\Support\Store::emailHref())

<x-layouts.storefront title="Contact us" description="Whether you need a bulk quote, compliance documentation, or help choosing the right gear for your site, our safety specialists are ready to assist.">
    <x-ui.section-heading title="Contact us" tag="h1" />

    <div class="mt-10 grid gap-10 md:grid-cols-2 md:gap-16">
        <section aria-labelledby="enquire-now">
            <h2 id="enquire-now" class="text-2xl text-brand-navy sm:text-3xl">Enquire Now</h2>
            <p class="mt-2 font-semibold text-brand-blue" data-contact-tagline>Safety Starts With the Right Gear. Talk to Our Specialists.</p>

            <p class="mt-6 leading-relaxed text-ink" data-contact-intro>Whether you need a bulk quote, compliance documentation, or help choosing the right gear for your site, our safety specialists are ready to assist.</p>

            <p class="mt-6 text-xl font-semibold" data-contact-email>
                @if ($emailHref)
                    <a href="{{ $emailHref }}" class="text-brand-blue hover:underline">{{ \App\Support\Store::email() }}</a>
                @else
                    <span class="text-brand-blue">{{ \App\Support\Store::email() }}</span>
                @endif
            </p>

            <address class="mt-4 not-italic leading-relaxed text-ink" data-contact-address>{{ \App\Support\Store::contactAddress() }}</address>
        </section>

        <livewire:contact.enquiry-form />
    </div>
</x-layouts.storefront>
