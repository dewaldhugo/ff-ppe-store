@php
    $user = \Filament\Facades\Filament::auth()->user();
@endphp

{{-- A Reports user (ReportsAccess::isReportsOnly) is kept out of Taxes by EnforceAdminAccess, but Lunar puts the Taxes link in the menu whatever the permissions, with no way to hide it for one role. So the group that holds it (Settings) is hidden for them, with a style rule. The 403 is the real guard; this only stops a link that goes nowhere. --}}
@if ($user instanceof \Lunar\Admin\Models\Staff && \App\Admin\ReportsAccess::isReportsOnly($user))
    <style data-reports-only-menu>
        .fi-sidebar-group:has(a[href$="/admin/taxes"]) { display: none; }
    </style>
@endif
