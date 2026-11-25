@props(['panel'])

{{--
    The "Safety standard" block on a product page, as on the vendor's own page: the heading, the class on a fill and the codes in
    small type on one line, and the standards in bold under it. From App\Domain\Catalogue\Display\SafetyStandardPanel; renders
    nothing when the product has none of it. What each code means is its hover text (title), so the block stays as short as the
    vendor's. The class is on teal with navy text (white on teal fails AA).
--}}
@if ($panel !== null)
    <section aria-labelledby="safety-standard-heading" class="mt-8" data-safety-standard>
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span id="safety-standard-heading" class="text-lg font-bold">Safety standard</span>

            @if ($panel['class'] !== null)
                <span class="rounded bg-brand-teal px-3 py-0.5 text-lg text-brand-navy" data-safety-class>{{ $panel['class'] }}</span>
            @endif

            @foreach ($panel['codes'] as $code)
                <span class="text-sm text-ink" data-safety-code @if (isset($panel['meanings'][$code])) title="{{ $panel['meanings'][$code] }}" @endif>{{ $code }}</span>
            @endforeach
        </p>

        @if ($panel['standards'] !== [])
            <p class="mt-1 text-lg font-bold text-ink" data-standards>{{ implode(' - ', $panel['standards']) }}</p>
        @endif
    </section>
@endif
