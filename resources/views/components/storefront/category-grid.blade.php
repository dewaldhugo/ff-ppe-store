{{-- Top-level categories as cards, from the same source as the menu. --}}
@if ($categories !== [])
    <ul {{ $attributes->class('grid gap-4 sm:grid-cols-2 lg:grid-cols-5') }} data-category-grid>
        @foreach ($categories as $category)
            @php($image = \App\Domain\Catalogue\Menu\CategoryCardImage::for($category['handle']))
            <li class="relative flex flex-col overflow-hidden rounded-lg border border-border bg-white transition-colors hover:border-brand-teal">
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
