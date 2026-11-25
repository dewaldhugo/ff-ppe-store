@props(['rows' => null])

@php
    // The example scorecard, one card for the Health Check page and the home page band. The rows come from
    // config/homepage.php (health_check.example). The scores are made up, labelled as an example, and the
    // lowest-scoring section named under the bars is worked out from them, so the card cannot contradict itself.
    $rows = array_values(array_filter($rows ?? (array) config('homepage.health_check.example', []), fn (mixed $row): bool => is_array($row) && isset($row['label'], $row['score'])));
    $lowest = $rows === [] ? null : collect($rows)->sortBy(fn (array $row): int => (int) $row['score'])->first();
@endphp

@if ($rows !== [])
    <div {{ $attributes->class('rounded-xl bg-white p-6 text-ink shadow-lg') }}>
        <p class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Example report</p>
        <div class="mt-3 flex items-center justify-between gap-4">
            <p class="text-xl font-semibold">Your PPE scorecard</p>
            <span class="rounded-full bg-surface-muted px-3 py-1 text-xs font-semibold text-brand-blue">Developing</span>
        </div>

        <ul class="mt-5 space-y-4">
            @foreach ($rows as $row)
                <li>
                    <p class="text-sm font-semibold">{{ $row['label'] }}</p>
                    <div class="mt-1.5 h-2 w-full rounded-full bg-border" role="meter" aria-label="{{ $row['label'] }}, example score" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $row['score'] }}">
                        <div class="h-2 rounded-full bg-brand-blue" style="width: {{ (int) $row['score'] }}%"></div>
                    </div>
                </li>
            @endforeach
        </ul>

        <p class="mt-5 border-t border-border pt-4 text-sm text-ink-muted">Lowest-scoring section: <span class="font-semibold text-ink">{{ $lowest['label'] }}</span></p>
    </div>
@endif
