{{-- Top-level categories as cards, from the same source as the menu. Each card is one link
     to its category; the subcategory links sit below the card, not inside it. --}}
@if ($categories !== [])
    <ul {{ $attributes->class('grid gap-x-4 gap-y-8 sm:grid-cols-2 lg:grid-cols-5') }} data-category-grid>
        @foreach ($categories as $category)
            @php($image = \App\Domain\Catalogue\Menu\CategoryCardImage::for($category['handle']))
            {{-- Two rows from the shared grid: every card in a row is as tall as the tallest, and the lists start level. --}}
            <li class="row-span-2 grid grid-rows-subgrid gap-y-0">
                <div class="relative flex flex-col overflow-hidden rounded-lg border border-border bg-white transition-colors hover:border-brand-teal" data-category-card>
                    @if ($image !== null)
                        {{-- Decorative: the category name below is the link text. --}}
                        <img
                            src="{{ $image['src'] }}"
                            alt=""
                            width="{{ $image['width'] }}"
                            height="{{ $image['height'] }}"
                            loading="lazy"
                            decoding="async"
                            class="aspect-[4/3] w-full object-cover"
                            data-category-image
                        >
                    @endif

                    <h3 class="p-4 text-lg text-ink">
                        <a href="{{ $category['url'] }}" class="text-brand-blue after:absolute after:inset-0 hover:underline">{{ $category['name'] }}</a>
                    </h3>
                </div>

                @if ($category['children'] !== [])
                    <ul class="mt-3 space-y-1.5 px-1 text-sm" data-category-children>
                        @foreach ($category['children'] as $child)
                            <li><a href="{{ $child['url'] }}" class="text-brand-blue hover:underline">{{ $child['name'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @endforeach
    </ul>
@endif
