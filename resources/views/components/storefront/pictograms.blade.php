@props(['pictograms'])

{{-- Safety Jogger's pictograms with their names, not links (App\Domain\Catalogue\Display\ProductPictograms). Nothing when there are none. --}}
@if ($pictograms !== [])
    <section aria-labelledby="pictograms-heading" class="mt-8" data-pictograms>
        <h2 id="pictograms-heading" class="text-lg font-bold">Features</h2>
        <ul class="mt-4 grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-3">
            @foreach ($pictograms as $pictogram)
                <li class="flex items-center gap-3" data-pictogram="{{ $pictogram['name'] }}">
                    <img src="{{ $pictogram['url'] }}" alt="" loading="lazy" width="48" height="49" class="h-12 w-12 shrink-0 object-contain">
                    <span class="text-sm text-ink">{{ $pictogram['label'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
