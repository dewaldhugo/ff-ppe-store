<x-layouts.account title="Log in" heading="Log in" :narrow="true">
    <form method="POST" action="{{ route('login') }}" novalidate class="grid gap-4" data-login-form>
        @csrf

        <x-ui.field name="email" label="Email address" type="email" autocomplete="username" maxlength="255" />
        <x-ui.field name="password" label="Password" type="password" autocomplete="current-password" maxlength="255" />

        <div>
            <label class="inline-flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded border-border-strong text-brand-blue focus:ring-2 focus:ring-brand-blue/30">
                Keep me logged in on this device
            </label>
        </div>

        <div>
            <x-ui.button type="submit">Log in</x-ui.button>
        </div>

        <p class="text-sm text-ink">
            New here?
            <a href="{{ route('register') }}" class="font-semibold text-brand-blue underline">Create an account</a>
        </p>
    </form>
</x-layouts.account>
