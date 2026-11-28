@php
    $label = 'block text-sm font-semibold text-ink';
    $field = 'mt-1 block w-full rounded-md border border-border-strong bg-white px-3 py-2.5 text-ink focus:border-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/30';
    $error = 'mt-1 text-sm text-danger';
@endphp

<div data-quote-form>
    @if ($sent)
        <div role="status" class="rounded-lg border border-success bg-surface-muted p-6" data-quote-sent>
            <p class="text-lg font-semibold text-ink">Thank you, your quote request has been sent.</p>
            <p class="mt-2 text-ink">We will look at it and come back to you with a price as soon as we can.</p>
            <p class="mt-4"><x-ui.button :href="route('shop')">Continue shopping</x-ui.button></p>
        </div>
    @else
        @if ($items !== [])
            <div class="mb-6 rounded-md border border-border bg-surface-muted p-4" data-quote-items>
                <h2 class="text-base font-semibold text-ink">The quote is for</h2>
                <ul class="mt-2 space-y-1 text-sm text-ink">
                    @foreach ($items as $item)
                        <li>
                            <span class="font-semibold">{{ $item['name'] }}</span>
                            @if (filled($item['detail'])) <span class="text-ink-muted">({{ $item['detail'] }})</span> @endif
                            @if (filled($item['quantity'])) <span class="text-ink-muted">x {{ $item['quantity'] }}</span> @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form wire:submit="submit" novalidate class="grid gap-4 sm:grid-cols-2">
            @error('form')
                <p role="alert" class="rounded-md border border-danger bg-surface-muted p-3 text-sm text-danger sm:col-span-2" data-quote-error>{{ $message }}</p>
            @enderror

            <div>
                <label for="quote-name" class="{{ $label }}">Full name</label>
                <input id="quote-name" type="text" wire:model="name" autocomplete="name" maxlength="100" required @error('name') aria-invalid="true" aria-describedby="quote-name-error" @enderror class="{{ $field }}">
                @error('name') <p id="quote-name-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="quote-email" class="{{ $label }}">Email address</label>
                <input id="quote-email" type="email" wire:model="email" autocomplete="email" maxlength="255" required @error('email') aria-invalid="true" aria-describedby="quote-email-error" @enderror class="{{ $field }}">
                @error('email') <p id="quote-email-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="quote-phone" class="{{ $label }}">Phone number <span class="font-normal text-ink-muted">(optional)</span></label>
                <input id="quote-phone" type="tel" wire:model="phone" autocomplete="tel" maxlength="30" @error('phone') aria-invalid="true" aria-describedby="quote-phone-error" @enderror class="{{ $field }}">
                @error('phone') <p id="quote-phone-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="quote-company" class="{{ $label }}">Company <span class="font-normal text-ink-muted">(optional)</span></label>
                <input id="quote-company" type="text" wire:model="company" autocomplete="organization" maxlength="150" @error('company') aria-invalid="true" aria-describedby="quote-company-error" @enderror class="{{ $field }}">
                @error('company') <p id="quote-company-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            @if (count($items) === 1 && blank($items[0]['quantity']))
                <div class="sm:col-span-2">
                    <label for="quote-quantity" class="{{ $label }}">How many do you need? <span class="font-normal text-ink-muted">(optional)</span></label>
                    <input id="quote-quantity" type="text" wire:model="quantity" maxlength="150" placeholder="For example 50 pairs, sizes 7 to 11" @error('quantity') aria-invalid="true" aria-describedby="quote-quantity-error" @enderror class="{{ $field }}" data-quote-quantity>
                    @error('quantity') <p id="quote-quantity-error" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="sm:col-span-2">
                <label for="quote-body" class="{{ $label }}">
                    @if ($items === []) What do you need a quote for? @else Anything else we should know? <span class="font-normal text-ink-muted">(optional)</span> @endif
                </label>
                <textarea id="quote-body" wire:model="body" rows="5" maxlength="3000" @if ($items === []) required @endif @error('body') aria-invalid="true" aria-describedby="quote-body-error" @enderror class="{{ $field }}"></textarea>
                @error('body') <p id="quote-body-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            {{-- Honeypot: out of sight and out of the tab order. --}}
            <div class="hidden" aria-hidden="true">
                <label for="quote-website">Website</label>
                <input id="quote-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="sm:col-span-2">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">Send quote request</x-ui.button>
                <p class="mt-3 text-xs text-ink-muted">We will use your details to reply to this request. <a href="{{ route('privacy') }}" class="underline">Read our privacy policy</a>.</p>
            </div>
        </form>
    @endif
</div>
