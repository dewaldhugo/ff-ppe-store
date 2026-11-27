{{-- The yearly breakdown (App\Admin\Pages\FinancialReporting, App\Domain\Orders\FinancialReport). --}}
<x-filament-panels::page>
    <style>
        /* The four tiles: a solid colour each, in Filament's own colours at shade 700 (white text stays above 4.5:1). */
        /* Four tiles in a row, two on a tablet, one on a phone. Plain CSS: the admin's own stylesheet only has the utility classes Filament uses itself. */
        .report-tiles { display: grid; gap: 1rem; grid-template-columns: repeat(4, minmax(0, 1fr)); }
        @media (max-width: 1023px) { .report-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 639px) { .report-tiles { grid-template-columns: minmax(0, 1fr); } }
        .report-note { font-size: 0.875rem; opacity: 0.7; }
        .report-tile { color: white; border-radius: 0.75rem; padding: 1rem 1.25rem; }
        .report-tile--success { background-color: var(--success-700); }
        .report-tile--danger { background-color: var(--danger-700); }
        .report-tile--info { background-color: var(--info-700); }
        .report-tile--warning { background-color: var(--warning-700); }
        .report-tile-value { font-size: 1.75rem; font-weight: 700; line-height: 2rem; }
        .report-tile-label { margin-top: 0.25rem; font-size: 0.875rem; }
    </style>

    @php
        $tiles = [
            ['success', $money($report['revenue']), 'Revenue (ex VAT)'],
            ['danger', $money($report['grossProfit']), 'Gross profit'],
            ['info', number_format($report['unitsSold']), 'Units sold'],
            ['warning', $report['margin'] ?? 'n/a', 'Gross profit margin'],
        ];
    @endphp

    <div class="report-tiles" data-report-tiles>
        @foreach ($tiles as [$colour, $value, $label])
            <div class="report-tile report-tile--{{ $colour }}">
                <div class="report-tile-value">{{ $value }}</div>
                <div class="report-tile-label">{{ $label }}</div>
            </div>
        @endforeach
    </div>

    <p class="report-note">
        Orders placed in {{ $report['year'] }}, not failed or cancelled. Revenue is the order totals before VAT, delivery included. Gross profit is the goods sold before VAT less the vendor cost
        (delivery is left out), and the margin is that profit as a share of the goods sold.
    </p>

    <x-admin.chart id="monthly-unit-sales" title="Monthly unit sales" type="line" :labels="$report['months']" :values="$report['units']" />
    <x-admin.chart id="monthly-turnover" title="Monthly turnover (ex VAT)" :labels="$report['months']" :values="$report['turnover']" :money="true" />
    <x-admin.chart id="units-per-customer" title="Total units per customer" :labels="array_column($report['customers'], 'label')" :values="array_column($report['customers'], 'units')" />
    <x-admin.chart id="monthly-gross-profit" title="Monthly gross profit" type="line" :labels="$report['months']" :values="$report['profit']" :money="true" />
    <x-admin.chart id="top-styles-sold" title="Top styles sold" :labels="array_column($report['styles'], 'label')" :values="array_column($report['styles'], 'units')" />
</x-filament-panels::page>
