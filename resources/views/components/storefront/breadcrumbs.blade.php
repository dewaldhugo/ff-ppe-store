@props(['items'])

{{-- A breadcrumb trail. The last item is the current page: text, not a link, marked aria-current. --}}
<nav aria-label="Breadcrumb" {{ $attributes->class('text-sm text-ink-muted') }} data-breadcrumbs>
    <ol class="flex flex-wrap gap-1">
        @foreach ($items as $item)
            <li @if ($item['url'] === null) aria-current="page" @endif>
                @if ($item['url'] !== null)
                    <a href="{{ $item['url'] }}" class="underline hover:text-brand-blue">{{ $item['name'] }}</a>
                    <span aria-hidden="true">/</span>
                @else
                    <span class="text-ink">{{ $item['name'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
