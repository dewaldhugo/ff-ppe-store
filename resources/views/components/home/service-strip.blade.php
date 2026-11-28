@php
    // Heroicons (outline) path data, by claim.
    $items = [
        ['label' => 'UK & EU Shipping', 'text' => 'Rapid nationwide dispatch', 'paths' => ['M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12']],
        ['label' => 'Best Offers', 'text' => 'Competitive bulk pricing', 'paths' => ['M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z', 'M6 6h.008v.008H6V6Z']],
        ['label' => 'Secure Payments', 'text' => 'Pay by card, securely online', 'paths' => ['M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z']],
        ['label' => '24/7 Support', 'text' => 'Send us an enquiry any time', 'paths' => ['M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z']],
    ];
@endphp

{{-- Service claims with icons, the last section of the home page: a brand-teal band with white text, so it stands out between the page and the charcoal footer. Each claim is a navy disc with a white icon, a bold label and a line under it, with a thin rule between them from the lg breakpoint. The lines under the labels are a draft for the client to confirm. --}}
<section class="bg-brand-teal text-white" aria-labelledby="home-service" data-home-service>
    <h2 id="home-service" class="sr-only">Our service</h2>

    <ul class="mx-auto grid max-w-7xl grid-cols-1 gap-y-8 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8 2xl:max-w-[92rem]">
        @foreach ($items as $item)
            <li @class(['flex items-center gap-4', 'lg:border-l lg:border-white/25 lg:pl-8' => ! $loop->first]) data-service-item>
                <span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-brand-navy text-white">
                    <svg class="size-7" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        @foreach ($item['paths'] as $path)
                            <path d="{{ $path }}"/>
                        @endforeach
                    </svg>
                </span>

                <span class="flex flex-col">
                    <span class="text-lg font-semibold leading-snug text-white">{{ $item['label'] }}</span>
                    <span class="text-sm text-white" data-service-text>{{ $item['text'] }}</span>
                </span>
            </li>
        @endforeach
    </ul>
</section>
