<x-layouts.member title="Your addresses" heading="Your addresses" intro="Delivery addresses you have saved. Checkout starts from your default one, and you can pick another there.">
    <p class="mb-6"><x-ui.button :href="route('account.addresses.create')" data-add-address>Add an address</x-ui.button></p>

    @if ($addresses->isEmpty())
        <div class="rounded border border-border bg-surface-muted p-6" data-addresses-empty>
            <p class="font-semibold">You have not saved a delivery address yet.</p>
            <p class="mt-1 text-ink-muted">Add one here, or tick "Save this address" at checkout.</p>
        </div>
    @else
        <ul class="grid gap-4 md:grid-cols-2" data-addresses-list>
            @foreach ($addresses as $address)
                <li class="flex flex-col rounded border border-border bg-white p-4" data-address>
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="font-bold">{{ $book::label($address) }}</h2>
                        @if ($address->shipping_default)
                            <span class="shrink-0 rounded border border-success bg-success-surface px-2 py-0.5 text-xs font-bold text-success" data-default-address>Default</span>
                        @endif
                    </div>

                    <p class="mt-2 text-sm text-ink-muted">
                        {{ trim($address->first_name.' '.$address->last_name) }}<br>
                        @if ($address->company_name){{ $address->company_name }}<br>@endif
                        {{ $address->line_one }}<br>
                        @if ($address->line_two){{ $address->line_two }}<br>@endif
                        {{ $address->city }}@if ($address->state), {{ $address->state }}@endif<br>
                        {{ $address->postcode }}
                        @if ($address->contact_phone)<br>{{ $address->contact_phone }}@endif
                    </p>

                    @if ($address->delivery_instructions)
                        <p class="mt-2 text-xs text-ink-muted">Delivery instructions: {{ $address->delivery_instructions }}</p>
                    @endif

                    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                        <a href="{{ route('account.addresses.edit', $address->id) }}" class="font-semibold text-brand-blue underline">Edit</a>

                        @unless ($address->shipping_default)
                            <form method="POST" action="{{ route('account.addresses.default', $address->id) }}">
                                @csrf
                                <button type="submit" class="font-semibold text-brand-blue underline" data-make-default>Make default</button>
                            </form>
                        @endunless

                        <form method="POST" action="{{ route('account.addresses.destroy', $address->id) }}" onsubmit="return confirm('Delete this address?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="font-semibold text-danger underline" data-delete-address>Delete</button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-layouts.member>
