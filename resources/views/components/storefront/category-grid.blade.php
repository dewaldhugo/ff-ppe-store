{{-- Top-level categories as cards, from the same source as the menu. --}}
@if ($categories !== [])
    <ul {{ $attributes->class('grid gap-4 sm:grid-cols-2 lg:grid-cols-3') }} data-category-grid>
        @foreach ($categories as $category)
            <li class="relative flex flex-col rounded-lg border border-border bg-white p-6 transition-colors hover:border-brand-teal">
                <h3 class="text-lg text-ink">
                    <a href="{{ $category['url'] }}" class="text-brand-blue after:absolute after:inset-0 hover:underline">{{ $category['name'] }}</a>
                </h3>

                @if ($category['children'] !== [])
                    <p class="mt-2 text-sm text-ink-muted">
                        {{ collect($category['children'])->pluck('name')->join(', ', ' and ') }}
                    </p>
                @endif
            </li>
        @endforeach
    </ul>
@endif
