@php
    $claims = array_values(array_filter(array_map('trim', (array) config('homepage.trust', [])), fn (string $claim): bool => $claim !== ''));

    // Heroicons (MIT), outline. One icon per claim slot, in the order of config/homepage.php: a price tag (trade
    // pricing), a gift (free wear trials), a shield with a tick (the standard) and a delivery van (the fourth slot).
    $icons = [
        'M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3ZM6 6h.008v.008H6V6Z',
        'M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
        'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
        'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
    ];
@endphp

{{-- Short claims from config/homepage.php, inside the hero at the bottom: the full width of the hero on a 60% black layer (.trust-scrim), text 1.134rem, split into equal blocks (a third each for three claims) by thin white borders, white text and icons. Empty slots are skipped. Hidden on a phone (below the sm breakpoint). --}}
@if ($claims !== [])
    <section class="trust-scrim relative hidden w-full border-t border-white/30 sm:block" aria-labelledby="home-trust" data-home-trust>
        <h2 id="home-trust" class="sr-only">Why buy from us</h2>

        <ul class="flex w-full divide-x divide-white/30">
            @foreach ($claims as $claim)
                <li class="flex flex-1 basis-0 items-center justify-center gap-3 px-4 py-5 text-center text-[1.134rem] font-normal text-white" data-trust-block>
                    <svg class="size-7 shrink-0 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="{{ $icons[$loop->index % count($icons)] }}"/>
                    </svg>
                    {{ $claim }}
                </li>
            @endforeach
        </ul>
    </section>
@endif
