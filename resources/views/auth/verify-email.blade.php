<x-layouts.account title="Verify your email address" heading="Verify your email address" :narrow="true">
    <div class="grid gap-4" data-verify-email>
        <p class="text-ink">
            We have sent a link to <strong class="font-semibold">{{ $email }}</strong>.
            Open it to verify your email address, then you can use your account.
        </p>
        <p class="text-sm text-ink-muted">Nothing arrived? Check your spam folder, or ask for another link.</p>

        <div class="flex flex-wrap items-center gap-4">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-ui.button type="submit">Send the link again</x-ui.button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="font-semibold text-brand-blue underline">Log out</button>
            </form>
        </div>
    </div>
</x-layouts.account>
