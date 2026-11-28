{{-- Top-level categories as cards, from the same source as the menu. --}}
@if ($categories !== [])
    <ul {{ $attributes->class('grid gap-4 sm:grid-cols-2 lg:grid-cols-5') }} data-category-grid>
        @foreach ($categories as $category)
            @php($image = \App\Domain\Catalogue\Menu\CategoryCardImage::for($category['handle']))
            {{-- The card is the group the photo's zoom follows (on hover, and on keyboard focus of its link). The photo sits in its own frame, which clips the zoom at the photo's own edges: a scaled image paints over what follows it, so without the frame it grew down over the category name. The zoom is not behind motion-safe: it happens whatever the "reduce motion" setting says, by the owner's decision. --}}
            <li class="group relative flex flex-col overflow-hidden rounded-lg border border-border bg-white transition-colors hover:border-brand-teal" data-category-card>
                @if ($image !== null)
                    {{-- Decorative: the category name below is the link text. --}}
                    <div class="aspect-[4/3] w-full overflow-hidden" data-category-image-frame>
                        <img
                            src="{{ $image['src'] }}"
                            alt=""
                            width="{{ $image['width'] }}"
                            height="{{ $image['height'] }}"
                            loading="lazy"
                            decoding="async"
                            class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110 group-focus-within:scale-110"
                            data-category-image
                        >
                    </div>
                @endif

                <div class="flex flex-1 flex-col p-6 lg:p-4">
                    <h3 class="text-lg text-ink">
                        <a href="{{ $category['url'] }}" class="text-brand-blue after:absolute after:inset-0 hover:underline">{{ $category['name'] }}</a>
                    </h3>

                    @if ($category['children'] !== [])
                        <p class="mt-2 text-sm text-ink-muted">
                            {{ collect($category['children'])->pluck('name')->join(', ', ' and ') }}
                        </p>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
