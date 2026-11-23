@props(['product'])

{{--
    Where the product can be delivered: "Delivers to the UK and the EU" (Safety Jogger) or "UK delivery only" (Footsure), from
    App\Domain\Catalogue\Markets\ShipsTo, each market with its flag. The flags are image files (public/images/flags): a template
    may not hold a colour code (DesignTokensTest), and a flag emoji shows as the letters GB on Windows. It is shown only while EU delivery is
    switched on (STORE_EU_DELIVERY): the store delivers to the UK only today, so every product is UK delivery only and saying so
    would only confuse. With the switch off this renders nothing. The flags are decoration: the words say it.
--}}
@if (\App\Support\Store::euDelivery())
    @php
        $markets = app(\App\Domain\Catalogue\Markets\ShipsTo::class)->forProduct($product);
        $flags = [\App\Domain\Catalogue\Markets\ShipsTo::UK => 'images/flags/gb.svg', \App\Domain\Catalogue\Markets\ShipsTo::EU => 'images/flags/eu.svg'];
    @endphp
    <p {{ $attributes->class('text-xs font-semibold text-ink') }} data-ships-to="{{ implode(',', $markets) }}">
        <span class="inline-flex items-center gap-1.5 rounded border border-border px-2 py-0.5">
            @foreach ($markets as $market)
                @isset($flags[$market])
                    <img src="{{ asset($flags[$market]) }}" alt="" width="27" height="18" class="h-[18px] w-[27px] shrink-0 rounded-[2px] border border-border" data-flag="{{ $market }}">
                @endisset
            @endforeach

            @if (in_array(\App\Domain\Catalogue\Markets\ShipsTo::EU, $markets, true))
                Delivers to the UK and the EU
            @else
                UK delivery only
            @endif
        </span>
    </p>
@endif
