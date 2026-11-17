{{--
    Contact page, in the storefront layout.
    Route: /contact (route name contact), see routes/web.php.

    Copy is supplied by the client. The enquiry form is the Livewire component
    App\Livewire\Contact\EnquiryForm; it emails the store email in config/store.php
    (or STORE_ENQUIRY_EMAIL when that is set). Left out on purpose: the hero photo and
    the sister-site buttons. The address and phone number are in the footer.
--}}
@php($emailHref = \App\Support\Store::emailHref())

<x-layouts.storefront title="Contact us" description="Tell us what you need and we will recommend a practical solution. Send an enquiry to The Bridge Group.">
    <x-ui.section-heading title="Contact us" tag="h1" />

    <div class="mt-10 grid gap-10 md:grid-cols-2 md:gap-16">
        <section aria-labelledby="enquire-now">
            <h2 id="enquire-now" class="text-2xl text-brand-navy sm:text-3xl">Enquire Now</h2>
            <p class="mt-2 font-semibold text-brand-blue">Your next challenge. Our next solution.</p>

            <p class="mt-6 leading-relaxed text-ink">Whatever your security, property or business support needs, we'll take the time to understand what matters to you and recommend a practical solution. Tell us what you need, and let's explore how Bridge can help.</p>

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
