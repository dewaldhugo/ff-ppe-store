{{-- The sign-in background of the admin: a photo under a dark overlay, behind the login card. Injected into the Lunar panel head (AppServiceProvider). It is scoped to Filament's simple layout (the sign-in, password reset and similar pages), so the dashboard and the rest of the admin are untouched. The overlay is black mixed with transparent, because the design token test allows no hex code or colour function in a template. --}}
<style>
    .fi-simple-layout {
        background:
            linear-gradient(color-mix(in srgb, black 85%, transparent), color-mix(in srgb, black 85%, transparent)),
            url('{{ asset('images/admin/login-bg.webp') }}') center / cover no-repeat;
        background-color: black;
    }
</style>
