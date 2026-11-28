{{--
    A search box with a category drop-down to its right: the words go to the chosen category's own page (or to the whole catalogue for
    "All categories"). A plain GET form: /search?q=...&category=... is sent on to the category by
    App\Http\Middleware\RedirectSearchToCategory, so it works without JavaScript. The options are the top-level categories, from the
    same source as the menu. The select is drawn by the page (appearance-none, with its own chevron) so it matches the box beside it.
--}}
@php
    $categories = app(\App\Domain\Catalogue\Menu\CategoryMenu::class)->tree();
    // The look the box and the drop-down share: the same border and height, a teal border on hover and a teal ring on focus.
    $field = 'w-full rounded-md border border-border-strong bg-white py-2.5 text-sm text-ink hover:border-brand-teal focus:border-brand-teal focus:outline-none focus:ring-2 focus:ring-brand-teal/40';
@endphp

<form action="{{ route('search') }}" method="get" role="search" {{ $attributes->class('flex flex-col gap-2 sm:flex-row') }} data-category-search>
    <div class="min-w-0 flex-1">
        <label for="category-search-q" class="sr-only">Search products</label>
        <input
            id="category-search-q"
            type="search"
            name="q"
            placeholder="Search by name, brand or safety class"
            class="{{ $field }} px-3"
            data-category-search-input
        >
    </div>

    <div class="relative sm:w-60 sm:shrink-0">
        <label for="category-search-category" class="sr-only">Category</label>
        <select id="category-search-category" name="category" class="{{ $field }} cursor-pointer appearance-none pl-3 pr-10 font-semibold text-brand-navy" data-category-search-select>
            <option value="">All categories</option>
            @foreach ($categories as $category)
                @if ($category['handle'] !== null)
                    <option value="{{ $category['handle'] }}">{{ $category['name'] }}</option>
                @endif
            @endforeach
        </select>
        <svg aria-hidden="true" class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-navy" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" data-category-search-chevron><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
    </div>

    <x-ui.button type="submit" class="px-5 py-2.5 text-sm">Search</x-ui.button>
</form>
