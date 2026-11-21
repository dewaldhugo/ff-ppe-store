{{--
    Terms and Conditions of Sale. Route: /terms (route name terms), see routes/web.php.

    A first draft written from how the store works (docs/cart.md, docs/accounts.md). The company details
    come from config/store.php, and the delivery sentence from DeliveryPolicy, so none can drift from the
    shop. It has not had legal review: see docs/launch-checklist.md before launch.
--}}
@php
    $company = \App\Support\Store::invoice('name');
    $number = \App\Support\Store::invoice('company_number');
    $vat = \App\Support\Store::invoice('vat_number');
    $address = \App\Support\Store::invoice('address');
    $email = \App\Support\Store::email();
    $phone = \App\Support\Store::phone();
    $delivery = app(\App\Domain\Cart\Delivery\DeliveryPolicy::class)->notice();
@endphp

<x-storefront.legal-page
    title="Terms and Conditions of Sale"
    description="The terms on which Frontline Safety Gear sells personal protective equipment to business customers in the UK."
    updated="21 November 2026"
>
    <h2>1. About these terms</h2>
    <p>This website, Frontline Safety Gear, is run by {{ $company }}, a company registered in England and Wales with company number {{ $number }} and VAT number {{ $vat }}. Our address is {{ $address }}. You can reach us by email at <a href="mailto:{{ $email }}">{{ $email }}</a> or by phone on {{ $phone }}.</p>
    <p>These terms apply to every order you place with us. By placing an order you agree to them. Please read them before you order. If anything is unclear, contact us first.</p>
    <p>We sell to businesses, for use by their employees and workers. By placing an order you confirm that you are buying for the purposes of your business.</p>

    <h2>2. Your account</h2>
    <ul>
        <li>You must register an account to see prices and to order. We activate each new account ourselves, and we may decline to activate one.</li>
        <li>Give us accurate details and keep them up to date. This includes your company name, address and VAT number.</li>
        <li>Keep your password private. You are responsible for everything done through your login, including orders placed by people in your business.</li>
    </ul>

    <h2>3. Our products</h2>
    <p>We describe and show our products as accurately as we can. Colours on screen can differ a little from the real product, and manufacturers can change a design or specification without notice.</p>
    <p>You are responsible for choosing personal protective equipment that is right for the risks your workers face, and for checking the standards and ratings shown for a product before you order it. Our <a href="{{ route('size-guide') }}">size guide</a> and <a href="{{ route('technical-features') }}">technical features</a> pages are there to help, but they do not replace your own risk assessment.</p>

    <h2>4. Prices and VAT</h2>
    <ul>
        <li>All prices are in pounds sterling (GBP) and are shown excluding VAT. VAT is added at the rate that applies and is shown in your cart and on your invoice.</li>
        <li>Prices are shown to signed-in customers only.</li>
        <li>We take care to price correctly. If we find a price is wrong, we will tell you before we dispatch the order and you can choose to confirm it at the right price or cancel it.</li>
    </ul>

    <h2>5. Placing an order</h2>
    <p>When you place an order you are offering to buy the goods in it. A contract between us is formed when we accept your order. We may decline an order, for example if goods are unavailable or a price is wrong. If we decline an order we will tell you, and we will refund anything you have paid for it.</p>
    <p>You can see your orders and invoices in your account.</p>

    <h2>6. Paying for your order</h2>
    <h3>Card payments</h3>
    <p>You can pay by credit or debit card. Card payments are handled by Stripe. Your card number is typed into Stripe&rsquo;s own form and is never sent to or stored by us.</p>
    <h3>30 day accounts</h3>
    <p>We can switch on 30 day account terms for a business. This is our decision for each customer, and we can switch it off again. An order placed on account is invoiced, and the invoice is due for payment within 30 days of the invoice date. Pay into the bank account shown on the invoice and quote the invoice number.</p>
    <p>If an invoice is paid late, we may claim interest and compensation under the Late Payment of Commercial Debts (Interest) Act 1998.</p>

    <h2>7. Delivery</h2>
    <p>We deliver to addresses in the United Kingdom. {{ $delivery }}</p>
    <p>Some of our products are dispatched to you directly by the supplier. An order that contains several products may arrive in more than one delivery. Delivery times and more detail are on our <a href="{{ route('delivery') }}">Delivery Information</a> page.</p>
    <p>Please check your goods when they arrive and tell us promptly if anything is damaged or missing, or is not what you ordered. Risk in the goods passes to you when they are delivered. Ownership passes to you when we have received payment for them in full.</p>

    <h2>8. Returns and refunds</h2>
    <p>Our <a href="{{ route('returns') }}">Returns and Refunds</a> page explains how to return goods and how refunds work. Nothing in these terms limits any legal right you have if goods are faulty, or are not as we described them.</p>

    <h2>9. Our responsibility to you</h2>
    <ul>
        <li>We do not limit or exclude our responsibility for death or personal injury caused by our negligence, for fraud or fraudulent misrepresentation, or for anything else the law does not allow us to limit.</li>
        <li>Subject to that, we are not responsible for loss of profit, loss of business, or any indirect or consequential loss.</li>
        <li>Subject to that, our total responsibility to you for any one order is limited to the price you paid for the goods in that order.</li>
    </ul>

    <h2>10. Events outside our control</h2>
    <p>We are not responsible for a delay or a failure to meet these terms that is caused by something outside our reasonable control, such as severe weather, industrial action, a failure of a carrier or supplier, or a problem with the public network. If that happens we will tell you and do what we reasonably can to limit the effect.</p>

    <h2>11. Your personal information</h2>
    <p>How we use your information is explained in our <a href="{{ route('privacy') }}">Privacy Policy</a>. How we use cookies is explained in our <a href="{{ route('cookies') }}">Cookie Policy</a>.</p>

    <h2>12. General</h2>
    <ul>
        <li><strong>Changes.</strong> We may change these terms. The terms that apply to an order are the ones on this page when you placed it.</li>
        <li><strong>The whole agreement.</strong> These terms, together with your order, are the whole agreement between us about the goods.</li>
        <li><strong>Other people.</strong> Only you and we have rights under a contract between us. Nobody else can enforce it.</li>
        <li><strong>If part of a term is invalid.</strong> If a court finds part of these terms cannot be enforced, the rest still applies.</li>
        <li><strong>Governing law.</strong> These terms, and any dispute about them or about an order, are governed by the law of England and Wales, and the courts of England and Wales have the right to decide any such dispute.</li>
    </ul>

    <h2>13. Contact us</h2>
    <p>If you have a question about these terms or about an order, email <a href="mailto:{{ $email }}">{{ $email }}</a>, phone {{ $phone }} or use our <a href="{{ route('contact') }}">contact form</a>.</p>
</x-storefront.legal-page>
