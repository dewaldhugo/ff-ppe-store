{{--
    Privacy Policy. Route: /privacy (route name privacy), see routes/web.php.

    A first draft written from what the store actually collects (registration, checkout, the contact form,
    docs/accounts.md, docs/cart.md). The company details come from config/store.php. It has not had legal
    review, and it names no ICO registration number because none has been supplied: see
    docs/launch-checklist.md before launch.
--}}
@php
    $company = \App\Support\Store::invoice('name');
    $number = \App\Support\Store::invoice('company_number');
    $address = \App\Support\Store::invoice('address');
    $email = \App\Support\Store::email();
    $phone = \App\Support\Store::phone();
@endphp

<x-storefront.legal-page
    title="Privacy Policy"
    description="How Frontline Safety Gear collects, uses and protects your personal information, and the rights you have over it."
    updated="21 November 2026"
>
    <h2>1. Who we are</h2>
    <p>{{ $company }} (company number {{ $number }}) runs the Frontline Safety Gear website. We are the controller of the personal information described here, which means we decide how and why it is used. Our address is {{ $address }}.</p>
    <p>For any question about your information, email <a href="mailto:{{ $email }}">{{ $email }}</a> or phone {{ $phone }}.</p>
    <p>We sell to businesses, so most of the information we hold is about people at those businesses, such as the person who orders, the person who receives a delivery and the person who pays an invoice.</p>

    <h2>2. What we collect and why</h2>
    <div class="legal-table-wrap">
        <table>
            <thead>
                <tr><th>When</th><th>What we collect</th><th>Why we use it</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>You register an account</td>
                    <td>Your name, company name, industry, business address, VAT number, phone number, email address and a password. We store the password only in a scrambled form that we cannot read.</td>
                    <td>To check and set up your account, to tell you when it is active, and to let you sign in.</td>
                </tr>
                <tr>
                    <td>You place an order</td>
                    <td>Your contact name, email and phone number, company name, VAT number, your purchase order and supplier numbers if you give them, billing and delivery addresses, any delivery instructions, what you ordered and how you paid.</td>
                    <td>To take and deliver your order, to invoice you, to take payment and to keep the records the law requires.</td>
                </tr>
                <tr>
                    <td>You pay by card</td>
                    <td>Your card details, which you type into the payment form of our payment provider, Stripe. They go to Stripe and not to us. We are told whether the payment worked.</td>
                    <td>To take payment for your order.</td>
                </tr>
                <tr>
                    <td>You use your account</td>
                    <td>Your favourite products, your saved addresses, your cart and your past orders and invoices.</td>
                    <td>To provide the account features, such as reordering and quick order.</td>
                </tr>
                <tr>
                    <td>You send us an enquiry</td>
                    <td>Your name, email address, phone number if you give it, industry, and your message.</td>
                    <td>To reply to you.</td>
                </tr>
                <tr>
                    <td>You visit the website</td>
                    <td>Technical information, such as your IP address, browser type and the pages you ask for, and the cookies described in our <a href="{{ route('cookies') }}">Cookie Policy</a>.</td>
                    <td>To keep the website working and secure, and to stop misuse.</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p>We do not currently send marketing emails. The emails we send are about your account, your orders and your enquiries, such as an activation notice or a password reset link. If we ever start to send marketing, we will tell you first and you will be able to say no.</p>

    <h2>3. Our legal reasons for using your information</h2>
    <ul>
        <li><strong>To carry out our contract with you</strong>, or to take steps you ask for before one: setting up your account, taking and delivering your orders, invoicing and taking payment.</li>
        <li><strong>To meet a legal duty</strong>, such as keeping accounting and tax records.</li>
        <li><strong>For our legitimate interests</strong>: running and securing our business, preventing fraud and misuse, answering enquiries and keeping our records in order. We balance these against your rights, and you can object (see section 7).</li>
    </ul>

    <h2>4. Who we share it with</h2>
    <p>We do not sell your information. We share it only as far as we need to, with:</p>
    <ul>
        <li><strong>Our suppliers and their carriers.</strong> Many of our products are dispatched to you directly by the supplier. We give them what they need to deliver, which is the products ordered and the delivery name, address and contact details.</li>
        <li><strong>Stripe</strong>, who process card payments.</li>
        <li><strong>The companies that provide our website hosting, email and other business systems</strong>, who handle information on our behalf and only on our instructions.</li>
        <li><strong>Our professional advisers</strong>, such as accountants and lawyers, when we need their advice.</li>
        <li><strong>Authorities</strong>, such as HMRC or the police, when the law requires it.</li>
    </ul>
    <p>Some of these companies may handle information outside the United Kingdom. When they do, we rely on the safeguards the law allows, such as a UK adequacy decision or approved standard contract terms.</p>

    <h2>5. How long we keep it</h2>
    <ul>
        <li><strong>Orders and invoices:</strong> for six years from the end of the financial year they relate to, because tax law requires us to keep accounting records.</li>
        <li><strong>Your account:</strong> while it is open, and for a reasonable time after, so that we can deal with any question about your orders.</li>
        <li><strong>Enquiries:</strong> for as long as we need to deal with them, and for a reasonable time after.</li>
    </ul>
    <p>When we no longer need information, we delete it or make it anonymous.</p>

    <h2>6. Keeping it safe</h2>
    <p>We use technical and organisational measures to protect your information, including secure connections, scrambled passwords and limits on who can see it. No system is perfectly secure. If a breach puts your rights at risk, we will tell you and the Information Commissioner&rsquo;s Office as the law requires.</p>

    <h2>7. Your rights</h2>
    <p>Under UK data protection law you have the right to:</p>
    <ul>
        <li>ask for a copy of the information we hold about you;</li>
        <li>ask us to correct information that is wrong or incomplete;</li>
        <li>ask us to delete your information, where we have no good reason to keep it;</li>
        <li>ask us to limit how we use it;</li>
        <li>ask for the information you gave us in a form you can move to someone else;</li>
        <li>object to our use of it where we rely on our legitimate interests.</li>
    </ul>
    <p>To use any of these rights, contact us using the details in section 1. We will reply within one month. We do not make decisions about you by automated means that have legal or similarly significant effects.</p>

    <h2>8. Complaints</h2>
    <p>Please tell us first if you are unhappy with how we have used your information, and we will try to put it right. You also have the right to complain to the Information Commissioner&rsquo;s Office, the UK data protection regulator, at <a href="https://ico.org.uk" rel="noopener">ico.org.uk</a>.</p>

    <h2>9. Our website is for businesses</h2>
    <p>Our website is meant for people buying for a business. It is not aimed at children, and we do not knowingly collect information from them.</p>

    <h2>10. Changes to this policy</h2>
    <p>We may update this policy. We will put the new version on this page with a new date at the top. If a change matters to you, we will tell you in another way as well.</p>
</x-storefront.legal-page>
