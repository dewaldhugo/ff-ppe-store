<x-layouts.account title="Choose a new password" heading="Choose a new password" :narrow="true">
    <form method="POST" action="{{ route('password.store') }}" novalidate class="grid gap-4" data-reset-password-form>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-ui.field name="email" label="Email address" type="email" :value="$email" autocomplete="username" maxlength="255" />
        <x-ui.field name="password" label="New password" type="password" autocomplete="new-password" maxlength="255" hint="At least 10 characters, with letters and numbers." />
        <x-ui.field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" maxlength="255" />

        <div>
            <x-ui.button type="submit">Reset password</x-ui.button>
        </div>
    </form>
</x-layouts.account>
