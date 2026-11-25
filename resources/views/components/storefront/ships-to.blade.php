@props(['product'])

{{--
    Where the product can be delivered: "Delivery: UK, EU" (Safety Jogger) or "Delivery: UK" (Footsure), from
    App\Domain\Catalogue\Markets\ShipsTo, each market with its flag (one or both; the EU's is 1px taller, as the dark blue looks smaller than the Union Flag at the same height). It is on the product page only, not on the
    cards in a listing. The flags are image files (public/images/flags): a template
    may not hold a colour code (DesignTokensTest), and a flag emoji shows as the letters GB on Windows. It is shown only while EU delivery is
    switched on (STORE_EU_DELIVERY): the store delivers to the UK only today, so every product is UK only and saying so
    would only confuse. With the switch off this renders nothing. The flags are decoration: the words say it.
--}}
@if (\App\Support\Store::euDelivery())
    @php
        $markets = app(\App\Domain\Catalogue\Markets\ShipsTo::class)->forProduct($product);
        $flags = [\App\Domain\Catalogue\Markets\ShipsTo::UK => 'images/flags/gb.svg', \App\Domain\Catalogue\Markets\ShipsTo::EU => 'images/flags/eu.svg'];
    @endphp
    <p {{ $attributes->class('flex items-center gap-2 text-sm font-semibold text-ink') }} data-ships-to="{{ implode(',', $markets) }}">
        <span class="flex items-center gap-1.5">
            @foreach ($markets as $market)
                @isset($flags[$market])
                    <img src="{{ asset($flags[$market]) }}" alt="" width="33" height="{{ $market === \App\Domain\Catalogue\Markets\ShipsTo::EU ? 23 : 22 }}" @class(['w-[33px] shrink-0 rounded-[3px] object-cover', 'h-[23px]' => $market === \App\Domain\Catalogue\Markets\ShipsTo::EU, 'h-[22px]' => $market !== \App\Domain\Catalogue\Markets\ShipsTo::EU]) data-flag="{{ $market }}">
                @endisset
            @endforeach
        </span>

        @if (in_array(\App\Domain\Catalogue\Markets\ShipsTo::EU, $markets, true))
            Delivery: UK, EU
        @else
            Delivery: UK
        @endif
    </p>
@endif
