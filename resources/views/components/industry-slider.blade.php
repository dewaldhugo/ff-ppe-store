{{--
    The industries row: photo cards that drift sideways at a steady, slow speed. Usage: <x-industry-slider />

    - Pure CSS, no JavaScript and no buttons (the styles are at the end of resources/css/app.css). The
      list is rendered twice; the track slides by one copy and starts again, so it loops without a jump.
      The second copy is hidden from assistive technology.
    - It fills the page container, not the full width of the page, and has no scrollbar.
    - It stops while the pointer is over it. It keeps moving whatever the "reduce motion" setting
      says, as the home hero slideshow does.
    - The cards are not links: the catalogue has no industry filter to send them to yet.

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
                    class="group relative mr-3 w-36 shrink-0 overflow-hidden rounded-lg bg-surface-muted sm:mr-4 sm:w-48 lg:w-56"
                    @if ($clone) aria-hidden="true" data-industry-clone @else data-industry-card @endif
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
                            class="aspect-square w-full object-cover object-top transition-transform duration-500 motion-safe:group-hover:scale-105"
                        >
                    </picture>
                    <p class="absolute inset-x-0 bottom-0 bg-linear-to-t from-brand-charcoal/90 via-brand-charcoal/60 to-transparent px-3 pb-3 pt-10 text-sm font-bold uppercase leading-tight text-white">{{ $label }}</p>
                </li>
            @endforeach
        @endforeach
    </ul>
    </div>
  </x-ui.container>
</section>
