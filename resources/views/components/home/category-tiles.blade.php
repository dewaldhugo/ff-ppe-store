{{-- Safety footwear categories, from the collection tree. Each tile is one link. --}}
@if ($tiles !== [])
    <section class="py-12 sm:py-16" aria-labelledby="home-categories" data-home-categories>
        <x-ui.container>
            <x-ui.section-heading id="home-categories" eyebrow="Safety footwear" title="Shop by type" />

            <ul class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tiles as $tile)
                    <li>
                        <a
                            href="{{ $tile['url'] }}"
                            aria-label="Shop {{ $tile['name'] }}"
                            class="group flex h-full flex-col overflow-hidden rounded-lg border border-border bg-white transition-colors hover:border-brand-teal"
                            data-category-tile
                        >
                            @if ($tile['image'])
                                <img
                                    src="{{ $tile['image']['src'] }}"
                                    srcset="{{ $tile['image']['srcset'] }}"
                                    sizes="(min-width: 1024px) 384px, (min-width: 640px) 50vw, 100vw"
                                    width="{{ $tile['image']['width'] }}"
                                    height="{{ $tile['image']['height'] }}"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                    class="aspect-square w-full bg-surface-muted object-contain"
                                >
                            @endif
                            <span class="p-6 text-lg font-semibold text-brand-blue group-hover:underline">{{ $tile['name'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
@endif
