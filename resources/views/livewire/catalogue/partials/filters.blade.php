{{-- Rendered twice (mobile disclosure and desktop sidebar), so ids take a prefix. --}}
<div class="space-y-6 text-sm">
    @if ($hasFilters)
        <button type="button" wire:click="clearFilters" class="underline">Clear all filters</button>
    @endif

    <div>
        <label class="flex items-center gap-2 font-semibold">
            <input type="checkbox" wire:model.live="in_stock" class="h-4 w-4">
            In stock only
        </label>
    </div>

    @foreach ($facetGroups as $group)
        <fieldset data-facet="{{ $group['name'] }}">
            <legend class="font-semibold">{{ $group['title'] }}</legend>
            {{-- The list scrolls, so it clips what sticks out of it: pl-1.5 keeps the left edge of each checkbox and its focus ring inside, and -ml-1.5 takes the room back so nothing moves. --}}
            <ul class="-ml-1.5 mt-2 max-h-64 space-y-1 overflow-y-auto pl-1.5 pr-1" data-facet-list>
                @foreach ($group['values'] as $value)
                    <li wire:key="{{ $idPrefix }}-{{ $group['name'] }}-{{ $value['value'] }}">
                        <label
                            class="flex items-center gap-2 {{ $value['count'] === 0 && ! $value['selected'] ? 'text-ink-muted' : '' }}"
                            @if ($value['tooltip']) title="{{ $value['tooltip'] }}" @endif
                        >
                            <input
                                type="checkbox"
                                wire:model.live="{{ $group['name'] }}"
                                value="{{ $value['value'] }}"
                                class="h-4 w-4"
                            >
                            @if ($group['name'] === 'colour')
                                <x-ui.swatch :hexes="$value['hexes']" aria-hidden="true" class="h-4 w-4" />
                            @endif
                            <span>{{ $value['label'] }}</span>
                            <span class="ml-auto text-ink-muted" data-count>({{ $value['count'] }})</span>
                        </label>
                    </li>
                @endforeach
            </ul>
        </fieldset>
    @endforeach

    @if ($showPrices)
    <fieldset>
        <legend class="font-semibold">Price (ex VAT)</legend>
        <div class="mt-2 flex items-center gap-2">
            <label for="{{ $idPrefix }}-price-min" class="sr-only">Minimum price in pounds</label>
            <input
                id="{{ $idPrefix }}-price-min"
                type="number"
                min="0"
                step="1"
                inputmode="numeric"
                wire:model.live.debounce.500ms="price_min"
                placeholder="{{ $result->priceFloor !== null ? '£'.intdiv($result->priceFloor, 100) : 'Min' }}"
                class="w-full rounded border border-border-strong px-2 py-1"
            >
            <span aria-hidden="true">to</span>
            <label for="{{ $idPrefix }}-price-max" class="sr-only">Maximum price in pounds</label>
            <input
                id="{{ $idPrefix }}-price-max"
                type="number"
                min="0"
                step="1"
                inputmode="numeric"
                wire:model.live.debounce.500ms="price_max"
                placeholder="{{ $result->priceCeiling !== null ? '£'.(int) ceil($result->priceCeiling / 100) : 'Max' }}"
                class="w-full rounded border border-border-strong px-2 py-1"
            >
        </div>
    </fieldset>
    @endif
</div>
