{{-- Admin panel density: injected into the Lunar panel head (AppServiceProvider). --}}
<style>
    /* Every rem in the panel (text, padding, gaps, icons, widths) scales together: fonts x .85. */
    html { font-size: 85%; }

    .fi-sidebar-header { height: 5.5rem; }
    .fi-sidebar-header .fi-logo { height: 1.8rem; }

    /* Squarer cards. Filament rounds stat widgets, sections, tables, tabs, fieldsets and modals with
       rounded-xl, which is var(--radius-xl) (.75rem). Lowering it changes all of them at once. */
    :root { --radius-xl: .25rem; }

    /* Tighter rows and headers. Filament pads these at py-3.5 / py-4. */
    .fi-ta-header-cell { padding-top: .5rem; padding-bottom: .5rem; }
    .fi-ta-text, .fi-ta-col-wrp, .fi-ta-icon, .fi-ta-color, .fi-ta-image, .fi-ta-checkbox { padding-top: .5rem !important; padding-bottom: .5rem !important; }
    .fi-ta-ctn .fi-ta-header, .fi-ta-header-ctn { padding-top: .5rem; padding-bottom: .5rem; }

    /* Sections, form fields and page chrome. */
    .fi-section-content, .fi-section-header { padding: .75rem 1rem; }
    .fi-fo-field, .fi-sc { row-gap: .75rem; }
    .fi-sc.fi-grid { gap: .75rem; }
    .fi-page-content, .fi-page-main { row-gap: 1rem; }
    .fi-main { padding-top: 1rem; padding-bottom: 1rem; }
    .fi-header { gap: .5rem; padding-bottom: .25rem; }
    .fi-sidebar-item-btn, .fi-topbar-item-btn { padding-top: .35rem; padding-bottom: .35rem; }
    .fi-input-wrp-input input, .fi-input-wrp-input select { padding-top: .3rem; padding-bottom: .3rem; }
    .fi-btn { padding-top: .3rem; padding-bottom: .3rem; }
</style>
