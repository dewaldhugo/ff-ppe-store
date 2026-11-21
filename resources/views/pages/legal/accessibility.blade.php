{{--
    Accessibility Statement. Route: /accessibility (route name accessibility), see routes/web.php.

    It claims only what the site is known to do (a skip link, labelled search, visible keyboard focus,
    colour pairs checked for WCAG AA contrast by DesignTokensTest) and says no formal audit has been
    done. Change the "Where we are now" section if one is carried out. It has not had legal review.
--}}
@php
    $company = \App\Support\Store::invoice('name');
    $email = \App\Support\Store::email();
    $phone = \App\Support\Store::phone();
@endphp

<x-storefront.legal-page
    title="Accessibility Statement"
    description="How Frontline Safety Gear works to make its website usable by everyone, what we know still needs work, and how to ask for help."
    updated="21 November 2026"
>
    <h2>1. Our commitment</h2>
    <p>{{ $company }} wants everyone who buys from Frontline Safety Gear to be able to use this website, including people who use a screen reader, a keyboard instead of a mouse, screen magnification or speech control. We aim for the website to meet level AA of the Web Content Accessibility Guidelines (WCAG) 2.2.</p>

    <h2>2. What we have done</h2>
    <ul>
        <li>Every page has a &ldquo;Skip to main content&rdquo; link, which appears when you press Tab.</li>
        <li>You can use the menus, search, forms and checkout with a keyboard, and the item you are on is clearly highlighted.</li>
        <li>The colours we use for text and buttons are checked against the WCAG AA contrast ratio.</li>
        <li>Form fields have labels, and form errors are described in words and not by colour alone.</li>
        <li>The pages are written in English and marked up with headings and landmarks to help screen reader users move around.</li>
        <li>The pages adjust to different screen sizes, and you can zoom in with your browser.</li>
    </ul>

    <h2>3. Where we are now</h2>
    <p>We have not yet carried out a full independent accessibility audit, so we cannot say the website meets WCAG 2.2 AA in every respect. We keep testing and improving it.</p>

    <h3>Things we know about</h3>
    <ul>
        <li>Many product photographs, and some of the images and size charts that explain technical features, come from our suppliers and manufacturers. Their descriptions for screen reader users may be short. If you need more detail about a product, contact us and we will help.</li>
        <li>The card payment form is provided by Stripe. We cannot change how it works, although we choose a provider that works to recognised accessibility standards.</li>
        <li>Invoices are web pages that you can print or save as a PDF from your browser. We do not supply a separate accessible document. If you need an invoice in another format, ask us.</li>
    </ul>

    <h2>4. Other ways to buy and to get information</h2>
    <p>If you find part of the website hard to use, or you need information in a different format, such as large print, tell us. You can email <a href="mailto:{{ $email }}">{{ $email }}</a>, phone {{ $phone }} or use our <a href="{{ route('contact') }}">contact form</a>. Tell us what the problem is, the page it is on and the device and any assistive technology you use. We will reply as soon as we can and will do our best to help you with your order another way.</p>

    <h2>5. If you are not happy with our reply</h2>
    <p>If you contact us about an accessibility problem and you are not happy with our reply, you can contact the Equality Advisory and Support Service (EASS), a free service that gives advice on equality and human rights. Their website is <a href="https://www.equalityadvisoryservice.com" rel="noopener">equalityadvisoryservice.com</a>.</p>

    <h2>6. About this statement</h2>
    <p>This statement was last updated on the date at the top of this page. We will review it when we make significant changes to the website, and after any audit.</p>
</x-storefront.legal-page>
