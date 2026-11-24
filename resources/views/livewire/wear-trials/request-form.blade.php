@php
    $label = 'block text-sm font-semibold text-ink';
    $field = 'mt-1 block w-full rounded-md border border-border-strong bg-white px-3 py-2.5 text-ink focus:border-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/30';
    $error = 'mt-1 text-sm text-danger';
@endphp

<div data-wear-trial-form>
    @if ($sent)
        <div role="status" class="rounded-lg border border-success bg-surface-muted p-6" data-wear-trial-sent>
            <p class="text-lg font-semibold text-ink">Thank you, your wear trial request has been sent.</p>
            <p class="mt-2 text-ink">We will read it and get back to you as soon as we can.</p>
            <button type="button" wire:click="$set('sent', false)" class="mt-4 font-semibold text-brand-blue underline">Send another request</button>
        </div>
    @else
        <form wire:submit="submit" novalidate class="grid gap-4 sm:grid-cols-2">
            @error('form')
                <p role="alert" class="rounded-md border border-danger bg-surface-muted p-3 text-sm text-danger sm:col-span-2" data-wear-trial-error>{{ $message }}</p>
            @enderror

            <div>
                <label for="trial-name" class="{{ $label }}">Full name</label>
                <input id="trial-name" type="text" wire:model="name" autocomplete="name" maxlength="100" required @error('name') aria-invalid="true" aria-describedby="trial-name-error" @enderror class="{{ $field }}">
                @error('name') <p id="trial-name-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="trial-company" class="{{ $label }}">Company name</label>
                <input id="trial-company" type="text" wire:model="company" autocomplete="organization" maxlength="150" required @error('company') aria-invalid="true" aria-describedby="trial-company-error" @enderror class="{{ $field }}">
                @error('company') <p id="trial-company-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="trial-email" class="{{ $label }}">Email address</label>
                <input id="trial-email" type="email" wire:model="email" autocomplete="email" maxlength="255" required @error('email') aria-invalid="true" aria-describedby="trial-email-error" @enderror class="{{ $field }}">
                @error('email') <p id="trial-email-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="trial-phone" class="{{ $label }}">Phone number <span class="font-normal text-ink-muted">(optional)</span></label>
                <input id="trial-phone" type="tel" wire:model="phone" autocomplete="tel" maxlength="30" @error('phone') aria-invalid="true" aria-describedby="trial-phone-error" @enderror class="{{ $field }}">
                @error('phone') <p id="trial-phone-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="trial-industry" class="{{ $label }}">Industry <span class="font-normal text-ink-muted">(optional)</span></label>
                <select id="trial-industry" wire:model="industry" @error('industry') aria-invalid="true" aria-describedby="trial-industry-error" @enderror class="{{ $field }}" data-wear-trial-industry>
                    <option value="">Choose your industry</option>
                    @foreach (\App\Domain\Accounts\Industries::all() as $slug => $industryName)
                        <option value="{{ $slug }}">{{ $industryName }}</option>
                    @endforeach
                </select>
                @error('industry') <p id="trial-industry-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="trial-products" class="{{ $label }}">Products you would like to trial</label>
                <input id="trial-products" type="text" wire:model="products" maxlength="300" required @error('products') aria-invalid="true" aria-describedby="trial-products-error" @enderror class="{{ $field }}">
                @error('products') <p id="trial-products-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="trial-details" class="{{ $label }}">Tell us about your team and what you need</label>
                <textarea id="trial-details" wire:model="details" rows="5" maxlength="5000" required @error('details') aria-invalid="true" aria-describedby="trial-details-error" @enderror class="{{ $field }}"></textarea>
                @error('details') <p id="trial-details-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            {{-- Honeypot: off screen and out of the tab order. People never see it. --}}
            <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
                <label for="trial-website">Leave this field empty</label>
                <input id="trial-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="sm:col-span-2">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">Request a wear trial</x-ui.button>
            </div>
        </form>
    @endif
</div>
