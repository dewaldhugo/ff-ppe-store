@props(['title', 'eyebrow' => null, 'tag' => 'h2', 'id' => null, 'divider' => true, 'center' => false])

{{-- An optional eyebrow, a heading, a short teal rule and an optional intro. --}}
<div {{ $attributes->class(['max-w-2xl', 'mx-auto text-center' => $center]) }}>
    @if ($eyebrow)
        <x-ui.eyebrow>{{ $eyebrow }}</x-ui.eyebrow>
    @endif

    <{{ $tag }} @if ($id) id="{{ $id }}" @endif class="mt-1 text-2xl text-ink sm:text-3xl">{{ $title }}</{{ $tag }}>

    @if ($divider)
        <x-ui.divider class="mt-3" />
    @endif

    @if (! $slot->isEmpty())
        <p class="mt-4 text-ink-muted">{{ $slot }}</p>
    @endif
</div>
