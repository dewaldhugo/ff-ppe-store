{{--
    The industries row: photo cards that drift sideways at a steady, slow speed, each a link to the products for that industry.
    Usage: <x-industry-slider />

    - Pure CSS, no JavaScript and no buttons (the styles are at the end of resources/css/app.css). The
      list is rendered twice; the track slides by one copy and starts again, so it loops without a jump.
      The second copy is hidden from assistive technology, and its links are out of the tab order.
    - It fills the page container, not the full width of the page, and has no scrollbar.
    - It keeps moving when the pointer is over it (hovering a card shows it is a link and does not stop the row:
      a teal frame fades in round the card, and an arrow slides in beside the label; the photo does not zoom;
      the same on keyboard focus). A link that has keyboard focus holds the row still, so it does not drift away
      from under the keyboard. The row and these hover effects all ignore the "reduce motion" setting, as the home
      hero slideshow does, by the owner's decision (so no motion-safe: on them).
    - Each card links to the search filtered by that industry: /search?industry[]=Construction. The label is the
      industry's name in the product data (the `industries` attribute, the `industry` filter), so it must read
      exactly as the filter's value does.

    Images are at resources/images/industries/{slug}.webp and {slug}.jpg (240 x 224), registered
    with Vite through the import.meta.glob in resources/js/app.js.
--}}
@php
    $industries = [
        'production'   => 'Industry',
        'logistics'    => 'Logistics',
        'construction' => 'Construction',
        'medical'      => 'Medical',
        'uniform'      => 'Uniform',
        'food'         => 'Food & beverages',
        'catering'     => 'Catering',
        'tactical'     => 'Tactical',
        'assembly'     => 'Assembly',
        'automotive'   => 'Automotive',
        'chemical'     => 'Chemical',
        'cleaning'     => 'Cleaning',
        'mining'       => 'Mining',
        'oilandgas'    => 'Oil & Gas',
    ];
@endphp

<section aria-label="Industries we supply" class="w-full" data-industry-row>
  <x-ui.container>
    <div class="industry-row">
    {{-- Each card has its own right margin (not a flex gap), so one copy is exactly half the track. --}}
    <ul role="list" class="industry-row-track flex w-max" data-industry-track>
        @foreach ([false, true] as $clone)
            @foreach ($industries as $slug => $label)
                <li
                    class="mr-3 w-36 shrink-0 sm:mr-4 sm:w-48 lg:w-56"
                    @if ($clone) aria-hidden="true" data-industry-clone @else data-industry-card @endif
                >
                    {{-- The whole card is the link. A teal frame and an arrow appear on hover and on keyboard focus, which is what says it is a link (the row keeps drifting, and the photo does not zoom).
                         The focus ring is drawn inside the card, because the card clips what sticks out of it. --}}
                    <a
                        href="{{ route('search', ['industry' => [$label]]) }}"
                        class="group relative block overflow-hidden rounded-lg bg-surface-muted focus-visible:shadow-none focus-visible:outline-offset-[-4px]"
                        @if ($clone) tabindex="-1" @else aria-label="Shop products for {{ $label }}" @endif
                        data-industry-link
                    >
                        <picture>
                            <source type="image/webp" srcset="{{ Vite::asset("resources/images/industries/{$slug}.webp") }}">
                            <img
                                src="{{ Vite::asset("resources/images/industries/{$slug}.jpg") }}"
                                alt=""
                                width="240"
                                height="224"
                                loading="lazy"
                                decoding="async"
                                class="aspect-square w-full object-cover object-top"
                            >
                        </picture>
                        {{-- The label on its dark gradient, with an arrow that slides in on hover and on focus: the card is a link. --}}
                        <div class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-2 bg-linear-to-t from-brand-charcoal/90 via-brand-charcoal/60 to-transparent px-3 pb-3 pt-10">
                            <p class="text-sm font-bold uppercase leading-tight text-white">{{ $label }}</p>
                            <svg class="size-5 shrink-0 text-brand-teal-bright opacity-0 transition duration-300 -translate-x-2 group-hover:opacity-100 group-focus-visible:opacity-100 group-hover:translate-x-0 group-focus-visible:translate-x-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-industry-arrow><path d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                        </div>
                        {{-- A teal frame that fades in over the card on hover and on focus (drawn on top, inside the card, so the clipping row does not cut it). --}}
                        <span class="pointer-events-none absolute inset-0 rounded-lg ring-2 ring-inset ring-transparent transition-colors duration-300 group-hover:ring-brand-teal-bright group-focus-visible:ring-brand-teal-bright" aria-hidden="true" data-industry-ring></span>
                    </a>
                </li>
            @endforeach
        @endforeach
    </ul>
    </div>
  </x-ui.container>
</section>
