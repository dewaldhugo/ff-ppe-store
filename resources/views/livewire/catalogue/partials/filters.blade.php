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
            <ul class="mt-2 max-h-64 space-y-1 overflow-y-auto pr-1">
                @foreach ($group['values'] as $value)
                    <li wire:key="{{ $idPrefix }}-{{ $group['name'] }}-{{ $value['value'] }}">
                        <label
                            class="flex items-center gap-2 {{ $value['count'] === 0 && ! $value['selected'] ? 'text-slate-400' : '' }}"
                            @if ($value['tooltip']) title="{{ $value['tooltip'] }}" @endif
                        >
                            <input
                                type="checkbox"
                                wire:model.live="{{ $group['name'] }}"
                                value="{{ $value['value'] }}"
                                class="h-4 w-4"
                            >
                            @if ($group['name'] === 'colour')
                                <span
                                    aria-hidden="true"
                                    class="inline-block h-4 w-4 rounded-full border border-slate-300"
                                    @if ($value['hex']) style="background-color: #{{ $value['hex'] }}" @endif
                                ></span>
                            @endif
                            <span>{{ $value['label'] }}</span>
                            <span class="ml-auto text-slate-500" data-count>({{ $value['count'] }})</span>
                        </label>
                    </li>
                @endforeach
            </ul>
        </fieldset>
    @endforeach

    <fieldset>
        <legend class="font-semibold">Price ({{ $priceDisplay->mode()->label() }})</legend>
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
                class="w-full rounded border border-slate-300 px-2 py-1"
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
                class="w-full rounded border border-slate-300 px-2 py-1"
            >
        </div>
    </fieldset>
</div>
