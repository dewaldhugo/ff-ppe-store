@props(['name'])

{{-- The small outline icon of a Tools link (the header beside the search, and the mobile menu): the same ruler and shield as the buying guide cards on the home page (App\Support\GuideIcons), so a page has the same icon wherever it is linked. --}}
<svg aria-hidden="true" {{ $attributes->class('h-5 w-5 shrink-0') }} fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" data-tool-icon="{{ $name }}">
    @foreach (\App\Support\GuideIcons::forTool($name) as $path)
        <path d="{{ $path }}"/>
    @endforeach
</svg>
