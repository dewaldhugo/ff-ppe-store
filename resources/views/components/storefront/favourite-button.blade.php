@props(['product'])

{{--
    The "Add to favourites" button (a bookmark), for the product page only and for signed-in customers only: it renders
    nothing for a guest. It is the same size as Add to cart and sits beside it, as the outlined secondary button. Alpine posts with fetch and flips the label at once; without JavaScript the form
    posts and goes back to the page.
--}}
@php
    $favourites = app(\App\Domain\Favourites\Favourites::class);
    $name = (string) $product->translateAttribute('name');
    $on = $favourites->has((int) $product->id);
@endphp

@if ($favourites->available())
    <form
        method="POST"
        action="{{ route('favourites.toggle', $product->id) }}"
        class="w-full sm:w-auto"
        data-favourite-form
        x-data="{
            on: {{ $on ? 'true' : 'false' }},
            busy: false,
            async toggle() {
                if (this.busy) return;
                this.busy = true;
                const was = this.on;
                this.on = ! was;
                try {
                    const response = await fetch(this.$el.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    });
                    if (! response.ok) throw new Error('failed');
                    const data = await response.json();
                    this.on = data.favourited;
                    window.dispatchEvent(new CustomEvent('favourites-updated', { detail: { count: data.count } }));
                } catch (error) {
                    this.on = was;
                } finally {
                    this.busy = false;
                }
            }
        }"
        x-on:submit.prevent="toggle()"
    >
        @csrf
        <button
            type="submit"
            class="inline-flex w-full items-center justify-center gap-2 rounded-md border-2 border-brand-teal px-8 py-3 text-lg font-bold text-brand-blue transition-colors hover:bg-surface-muted sm:w-72"
            data-favourite-button
            data-favourited="{{ $on ? 'true' : 'false' }}"
            aria-pressed="{{ $on ? 'true' : 'false' }}"
            aria-label="{{ $on ? 'Remove '.$name.' from favourites' : 'Add '.$name.' to favourites' }}"
            x-bind:aria-pressed="on.toString()"
            x-bind:data-favourited="on.toString()"
            x-bind:aria-label="on ? @js('Remove '.$name.' from favourites') : @js('Add '.$name.' to favourites')"
        >
            <svg aria-hidden="true" class="h-6 w-6" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round" fill="{{ $on ? 'currentColor' : 'none' }}" x-bind:fill="on ? 'currentColor' : 'none'" data-bookmark-icon><path d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z"/></svg>
            <span x-text="on ? 'Saved to favourites' : 'Add to favourites'">{{ $on ? 'Saved to favourites' : 'Add to favourites' }}</span>
        </button>
    </form>
@endif
