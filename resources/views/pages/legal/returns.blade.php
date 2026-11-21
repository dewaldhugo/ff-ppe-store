{{--
    Returns and Refunds. Route: /returns (route name returns), see routes/web.php.

    Generic good practice for a UK business-to-business store. The three periods (window, report, refund)
    come from config/store.php (`returns`) and were assumed, not supplied by the client: confirm them before
    launch (docs/launch-checklist.md). It does not promise a refund of the delivery charge or a collection
    service, because the store has neither. It has not had legal review.
--}}
@php
    $window = (int) config('store.returns.window_days');
    $reportDays = (int) config('store.returns.report_days');
    $refundDays = (int) config('store.returns.refund_days');
    $email = \App\Support\Store::email();
    $phone = \App\Support\Store::phone();
@endphp

<x-storefront.legal-page
    title="Returns and Refunds"
    description="How to return goods to Frontline Safety Gear, which items we can take back and how refunds are made."
    updated="21 November 2026"
>
    <h2>1. Our approach</h2>
    <p>We sell to businesses. The 14 day right to cancel that applies to sales to consumers does not apply to business orders, so what follows is what we offer. It does not affect any legal right you have if goods are faulty or are not as we described them.</p>

    <h2>2. Goods that are damaged, wrong, missing or faulty</h2>
    <ul>
        <li><strong>Damaged, wrong or missing on arrival.</strong> Tell us within {{ $reportDays }} working days of delivery. Give us your order reference and, if you can, photographs of the goods and the packaging.</li>
        <li><strong>Faulty.</strong> If goods develop a fault within a reasonable time, stop using them and tell us. Safety equipment that may be faulty should not be worn or used.</li>
    </ul>
    <p>We will check what has happened and then replace the goods, repair them or refund you, whichever is right for the problem. We will pay for the goods to come back to us. Please wait for our instructions before you send anything.</p>

    <h2>3. Goods you no longer want or need</h2>
    <p>If you have ordered the wrong item or size, or no longer need the goods, you can return them to us within {{ $window }} days of delivery, if:</p>
    <ul>
        <li>they are unused and unworn, apart from trying footwear and clothing on indoors;</li>
        <li>they are clean, undamaged and in their original packaging, with all tags and labels;</li>
        <li>you have our agreement to the return first (see section 5); and</li>
        <li>you send them back to us in a way that protects them.</li>
    </ul>
    <p>You pay to send these goods back. We refund the price of the goods you return, ex VAT, and VAT on it. We do not refund the delivery charge.</p>

    <h3>Goods we cannot take back unless they are faulty</h3>
    <ul>
        <li>Hygiene products, such as earplugs, respirators and face masks, once the packaging has been opened.</li>
        <li>Goods that have been worn, used, washed or altered.</li>
        <li>Goods made, printed or marked for you, or ordered specially for you.</li>
        <li>Goods without their original packaging.</li>
    </ul>

    <h2>4. Exchanges</h2>
    <p>If you need a different size or colour, tell us when you ask to return the goods and we will exchange them if the one you want is in stock. If it is not, you can return the goods for a refund and place a new order.</p>

    <h2>5. How to make a return</h2>
    <ol>
        <li>Contact us by email at <a href="mailto:{{ $email }}">{{ $email }}</a>, by phone on {{ $phone }} or through our <a href="{{ route('contact') }}">contact form</a>. Give your order reference (you will find it in your account, under <a href="{{ route('account.orders') }}">Orders</a>), the goods and quantities, and the reason.</li>
        <li>We will reply with instructions. As some goods are sent to you by our suppliers, the address to return them to may not be ours. <strong>Please do not send goods back without our instructions.</strong></li>
        <li>Pack the goods securely and keep your proof of postage. The goods remain your responsibility until they reach us.</li>
    </ol>

    <h2>6. Refunds</h2>
    <ul>
        <li>We check returned goods when they arrive. We then tell you whether we accept the return.</li>
        <li>We make the refund within {{ $refundDays }} days of accepting it.</li>
        <li><strong>Orders paid by card</strong> are refunded to the card used. Your bank may take a few days to show it.</li>
        <li><strong>Orders on a 30 day account</strong> are credited against the invoice or, if you have paid it, refunded to you. We will tell you which.</li>
        <li>If the goods we get back are not as described in section 3, we may refuse the refund or take off an amount for the loss in their value. We will tell you if we do.</li>
    </ul>

    <h2>7. Cancelling an order</h2>
    <p>If you want to cancel an order, contact us as soon as you can. If it has not been dispatched we will try to cancel it and refund you in full. If it has been dispatched, treat it as a return and follow section 3.</p>

    <h2>8. Questions</h2>
    <p>Email <a href="mailto:{{ $email }}">{{ $email }}</a>, phone {{ $phone }} or use our <a href="{{ route('contact') }}">contact form</a>. Our <a href="{{ route('delivery') }}">Delivery Information</a> and <a href="{{ route('terms') }}">Terms and Conditions of Sale</a> pages have more.</p>
</x-storefront.legal-page>
