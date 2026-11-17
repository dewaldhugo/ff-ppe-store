@php
    $label = 'block text-sm font-semibold text-ink';
    $field = 'mt-1 block w-full rounded-md border border-border-strong bg-white px-3 py-2.5 text-ink focus:border-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/30';
    $error = 'mt-1 text-sm text-danger';
@endphp

<div data-enquiry-form>
    @if ($sent)
        <div role="status" class="rounded-lg border border-success bg-surface-muted p-6" data-enquiry-sent>
            <p class="text-lg font-semibold text-ink">Thank you, your enquiry has been sent.</p>
            <p class="mt-2 text-ink">We will read it and get back to you as soon as we can.</p>
            <button type="button" wire:click="$set('sent', false)" class="mt-4 font-semibold text-brand-blue underline">Send another enquiry</button>
        </div>
    @else
        <form wire:submit="submit" novalidate class="grid gap-4 sm:grid-cols-2">
            @error('form')
                <p role="alert" class="rounded-md border border-danger bg-surface-muted p-3 text-sm text-danger sm:col-span-2" data-enquiry-error>{{ $message }}</p>
            @enderror

            <div>
                <label for="enquiry-name" class="{{ $label }}">Full name</label>
                <input id="enquiry-name" type="text" wire:model="name" autocomplete="name" maxlength="100" required @error('name') aria-invalid="true" aria-describedby="enquiry-name-error" @enderror class="{{ $field }}">
                @error('name') <p id="enquiry-name-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="enquiry-email" class="{{ $label }}">Email address</label>
                <input id="enquiry-email" type="email" wire:model="email" autocomplete="email" maxlength="255" required @error('email') aria-invalid="true" aria-describedby="enquiry-email-error" @enderror class="{{ $field }}">
                @error('email') <p id="enquiry-email-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="enquiry-phone" class="{{ $label }}">Phone number <span class="font-normal text-ink-muted">(optional)</span></label>
                <input id="enquiry-phone" type="tel" wire:model="phone" autocomplete="tel" maxlength="30" @error('phone') aria-invalid="true" aria-describedby="enquiry-phone-error" @enderror class="{{ $field }}">
                @error('phone') <p id="enquiry-phone-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="enquiry-subject" class="{{ $label }}">Enquiry subject</label>
                <input id="enquiry-subject" type="text" wire:model="enquirySubject" maxlength="150" required @error('enquirySubject') aria-invalid="true" aria-describedby="enquiry-subject-error" @enderror class="{{ $field }}">
                @error('enquirySubject') <p id="enquiry-subject-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="enquiry-body" class="{{ $label }}">How can we help?</label>
                <textarea id="enquiry-body" wire:model="body" rows="5" maxlength="5000" required @error('body') aria-invalid="true" aria-describedby="enquiry-body-error" @enderror class="{{ $field }}"></textarea>
                @error('body') <p id="enquiry-body-error" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            {{-- Honeypot: off screen and out of the tab order. People never see it. --}}
            <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
                <label for="enquiry-website">Leave this field empty</label>
                <input id="enquiry-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="sm:col-span-2">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submit">Submit</x-ui.button>
            </div>
        </form>
    @endif
</div>
