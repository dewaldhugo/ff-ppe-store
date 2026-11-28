@props(['product'])

{{--
    The "Compare" tick on a product card and on the product page: open to everyone, as the choice is kept in the session
    (App\Domain\Compare\Compare). Alpine posts with fetch and ticks at once (and un-ticks if the answer is no: four products at most);
    without JavaScript the form posts and goes back to the page. Every tick on the page follows the others through the
    compare-updated event (Clear in the bar, a product taken out). It sits above the card's stretched link (relative z-10).
--}}
@php
    $compare = app(\App\Domain\Compare\Compare::class);
    $name = (string) $product->translateAttribute('name');
@endphp

<form
    method="POST"
    action="{{ route('compare.toggle', $product->id) }}"
    {{ $attributes->class('relative z-10') }}
    data-compare-form
    x-data="{
        on: {{ $compare->has((int) $product->id) ? 'true' : 'false' }},
        busy: false,
        async toggle() {
            if (this.busy) return;
            this.busy = true;
            const was = this.on;
            this.on = ! was;
            try {
                const response = await fetch(this.$root.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                if (! response.ok) throw new Error('failed');
                const data = await response.json();
                this.on = data.on;
                window.dispatchEvent(new CustomEvent('compare-updated', { detail: data }));
            } catch (error) {
                this.on = was;
            } finally {
                this.busy = false;
            }
        }
    }"
    x-on:submit.prevent="toggle()"
    x-on:compare-updated.window="on = $event.detail.ids.includes({{ (int) $product->id }})"
>
    @csrf
    <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-ink-muted hover:text-brand-blue">
        <input type="checkbox" class="size-4 cursor-pointer rounded border-border-strong accent-brand-teal" x-bind:checked="on" x-on:change.prevent="toggle()" @checked($compare->has((int) $product->id)) data-compare-checkbox>
        <span>Compare<span class="sr-only"> {{ $name }}</span></span>
    </label>
    <noscript><button type="submit" class="ml-2 text-sm underline">{{ $compare->has((int) $product->id) ? 'Remove from compare' : 'Add to compare' }}</button></noscript>
</form>
