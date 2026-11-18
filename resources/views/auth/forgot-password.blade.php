<x-layouts.account title="Forgot your password" heading="Forgot your password?" :narrow="true">
    <p class="text-ink-muted">Enter the email address you registered with and we will email you a link to choose a new password.</p>

    <form method="POST" action="{{ route('password.email') }}" novalidate class="mt-6 grid gap-4" data-forgot-password-form>
        @csrf

        <x-ui.field name="email" label="Email address" type="email" autocomplete="email" maxlength="255" />

        <div>
            <x-ui.button type="submit">Email me a link</x-ui.button>
        </div>

        <p class="text-sm text-ink">
            <a href="{{ route('login') }}" class="font-semibold text-brand-blue underline">Back to log in</a>
        </p>
    </form>
</x-layouts.account>
