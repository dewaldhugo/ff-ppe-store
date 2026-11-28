{{--
    The bar along the bottom of the screen while products are ticked to compare: how many, the Compare button (from two), and Clear.
    Hidden when nothing is ticked, and without JavaScript (the tick itself still works as a form post; the page is /compare).
    It follows the ticks through the compare-updated event.
--}}
@php
    $compare = app(\App\Domain\Compare\Compare::class);
@endphp

<div
    class="surface-dark fixed inset-x-0 bottom-0 z-30 border-t border-white/10 bg-brand-charcoal text-white shadow-lg"
    x-data="{
        count: {{ $compare->count() }},
        full: false,
        async clear() {
            const response = await fetch('{{ route('compare.clear') }}', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            });
            if (response.ok) window.dispatchEvent(new CustomEvent('compare-updated', { detail: { count: 0, ids: [], full: false } }));
        }
    }"
    x-on:compare-updated.window="count = $event.detail.count; full = !! $event.detail.full"
    x-show="count > 0"
    x-cloak
    aria-live="polite"
    data-compare-bar
>
    <x-ui.container class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 py-3">
        <p class="text-sm">
            <span class="font-semibold"><span x-text="count">{{ $compare->count() }}</span> of {{ \App\Domain\Compare\Compare::MAX }}</span> products to compare
            <span x-show="full" x-cloak class="ml-2 text-brand-orange" data-compare-full>You can compare up to {{ \App\Domain\Compare\Compare::MAX }} at once.</span>
        </p>

        <div class="flex items-center gap-4">
            <button type="button" class="text-sm underline" x-on:click="clear()" data-compare-clear>Clear</button>
            <span x-show="count < 2" class="text-sm text-white/90">Pick at least 2</span>
            <x-ui.button variant="accent" :href="route('compare')" x-show="count >= 2" class="px-5 py-2 text-sm" data-compare-link>Compare</x-ui.button>
        </div>
    </x-ui.container>
</div>
