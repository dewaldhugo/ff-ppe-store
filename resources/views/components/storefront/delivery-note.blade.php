{{--
    The delivery note: which orders get free delivery, and what delivery costs otherwise. The wording
    comes from DeliveryPolicy, which also decides the charge, so the note and the charge cannot disagree.
--}}
<p {{ $attributes->class(['text-sm text-ink-muted']) }} data-delivery-note>{{ app(\App\Domain\Cart\Delivery\DeliveryPolicy::class)->notice() }}</p>
