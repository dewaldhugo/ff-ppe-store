@props(['title', 'heading', 'narrow' => false])

{{-- The storefront layout for sign-in, registration and My Account pages: noindex, a heading,
     one flash message (`status`) and a centred column. --}}
<x-layouts.storefront :title="$title" :noindex="true">
    <div class="mx-auto py-4 {{ $narrow ? 'max-w-md' : 'max-w-3xl' }}">
        <x-ui.section-heading :title="$heading" tag="h1" />

        @if (session('status'))
            <x-ui.alert class="mt-6" data-flash>{{ session('status') }}</x-ui.alert>
        @endif

        <div class="mt-8">
            {{ $slot }}
        </div>
    </div>
</x-layouts.storefront>
