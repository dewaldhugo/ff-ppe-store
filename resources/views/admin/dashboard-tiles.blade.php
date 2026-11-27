{{-- The sales tiles at the top of the admin dashboard: injected into the Lunar panel head (AppServiceProvider). Nothing else in the admin is restyled; the rest is Lunar's own. --}}
<style>
    /* App\Admin\Widgets\SalesTilesWidget: a solid colour each, in Filament's own colours at shade 700, which keeps
       white text above 4.5:1 contrast. */
    .fi-wi-stats-overview-stat.sales-tile { color: white; --tw-ring-color: transparent; }
    .fi-wi-stats-overview-stat.sales-tile--success { background-color: var(--success-700) !important; }
    .fi-wi-stats-overview-stat.sales-tile--danger { background-color: var(--danger-700) !important; }
    .fi-wi-stats-overview-stat.sales-tile--info { background-color: var(--info-700) !important; }
    .fi-wi-stats-overview-stat.sales-tile--warning { background-color: var(--warning-700) !important; }
    /* The rand value beside the pounds on a money tile: small and muted. */
    .sales-tile .sales-tile-zar { margin-left: 0.5rem; font-size: 0.8rem; font-weight: 400; opacity: 0.75; white-space: nowrap; }
    /* The gross profit tile has a row of its own, across every column. */
    .fi-wi-stats-overview-stat.sales-tile--wide { grid-column: 1 / -1; }
    .sales-tile .fi-wi-stats-overview-stat-label,
    .sales-tile .fi-wi-stats-overview-stat-value { color: white !important; }
</style>
