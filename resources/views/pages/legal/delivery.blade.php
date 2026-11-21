{{--
    Delivery Information. Route: /delivery (route name delivery), see routes/web.php.

    Generic good practice for a UK business dropshipping store. The charge and the free delivery level come
    from DeliveryPolicy (the checkout's own rule), the report window from config/store.php (`returns`). It
    states no dispatch or delivery time because none has been confirmed with the client: add one when it is
    (docs/launch-checklist.md). It has not had legal review.
--}}
@php
    $policy = app(\App\Domain\Cart\Delivery\DeliveryPolicy::class);
    $freeOver = $policy->freeOverFormatted();
    $charge = $policy->chargeFormatted();
    $reportDays = (int) config('store.returns.report_days');
    $email = \App\Support\Store::email();
    $phone = \App\Support\Store::phone();
@endphp

<x-storefront.legal-page
    title="Delivery Information"
    description="Where Frontline Safety Gear delivers, what delivery costs, how orders are sent and what to do when your goods arrive."
    updated="21 November 2026"
>
    <h2>1. Where we deliver</h2>
    <p>We deliver to addresses in the United Kingdom. We do not deliver outside the UK. If you need goods sent somewhere else, contact us before you order and we will see what we can do.</p>

    <h2>2. What delivery costs</h2>
    <ul>
        <li>Orders over {{ $freeOver }} ex VAT get free delivery.</li>
        <li>On any other order, delivery is {{ $charge }} ex VAT.</li>
    </ul>
    <p>To work out whether an order qualifies, we add up the price of the goods ex VAT, before any discount and before delivery. An order of exactly {{ $freeOver }} does not qualify: it has to be more. VAT is added to the delivery charge, as it is to the goods. You pay the delivery charge once for each order, even if the order is sent in more than one delivery.</p>
    <p>Your cart and checkout show the delivery charge before you place the order.</p>

    <h2>3. How your order is sent</h2>
    <p>We work with a number of suppliers, and many products are sent to you directly by the supplier. This means:</p>
    <ul>
        <li>An order with several products may arrive in more than one delivery, on different days and from different places.</li>
        <li>The parcel may come from the supplier and not from us. The goods will still be the products you ordered.</li>
        <li>Delivery times depend on whether the product is in stock and on which supplier sends it.</li>
    </ul>
    <p>Working days are Monday to Friday, not including bank holidays. If you need goods by a particular date, contact us before you order and we will tell you whether we can meet it.</p>

    <h2>4. Following your order</h2>
    <p>You can see your orders in your account, under <a href="{{ route('account.orders') }}">Orders</a>. If you want to know where an order is, email <a href="mailto:{{ $email }}">{{ $email }}</a> or phone {{ $phone }} with your order reference and we will find out for you.</p>

    <h2>5. Your delivery address</h2>
    <ul>
        <li>Check the delivery address carefully when you place the order. Goods are sent to the address you give us.</li>
        <li>Make sure someone is there to receive the delivery. Many carriers cannot leave goods without a signature.</li>
        <li>Use the delivery instructions box at the checkout for anything the driver should know, such as a receiving bay or opening hours.</li>
        <li>Tell us straight away if you need to change an address. We cannot always change it once the order has been dispatched.</li>
    </ul>

    <h2>6. When your goods arrive</h2>
    <p>Please check the packaging and the goods when they arrive. If a parcel is damaged, note it on the carrier&rsquo;s delivery note before you sign, if you can. Then tell us within {{ $reportDays }} working days of delivery if:</p>
    <ul>
        <li>goods are damaged;</li>
        <li>goods are missing, or the quantity is wrong; or</li>
        <li>you have received something you did not order.</li>
    </ul>
    <p>Give us your order reference and, where possible, photographs of the goods and the packaging. We will put it right. Our <a href="{{ route('returns') }}">Returns and Refunds</a> page explains what happens next. Risk in the goods passes to you when they are delivered.</p>

    <h2>7. If your order is late</h2>
    <p>Contact us if your order has not arrived when you expected it. We will check with the supplier and the carrier and let you know what we find. Delays can happen, for example in bad weather or at busy times, and we will keep you informed if we hear of one.</p>

    <h2>8. Questions</h2>
    <p>Email <a href="mailto:{{ $email }}">{{ $email }}</a>, phone {{ $phone }} or use our <a href="{{ route('contact') }}">contact form</a>. More about orders and payment is in our <a href="{{ route('terms') }}">Terms and Conditions of Sale</a>.</p>
</x-storefront.legal-page>
