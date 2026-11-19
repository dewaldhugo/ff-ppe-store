@props([
    'name',
    'label',
    'type' => 'text',
    'optional' => false,
    'autocomplete' => null,
    'maxlength' => 255,
    'hint' => null,
])

{{-- A labelled Livewire field for the checkout form, with its validation error. The value binds to the component property of the same name. --}}
@php
    $id = 'checkout-'.str_replace('_', '-', $name);
    $describedBy = trim(($errors->has($name) ? $id.'-error' : '').' '.($hint ? $id.'-hint' : ''));
@endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="block text-sm font-semibold text-ink">
        {{ $label }}
        @if ($optional)
            <span class="font-normal text-ink-muted">(optional)</span>
        @endif
    </label>
    <input
        id="{{ $id }}"
        type="{{ $type }}"
        wire:model.blur="{{ $name }}"
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        maxlength="{{ $maxlength }}"
        @if ($errors->has($name)) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        class="mt-1 block w-full rounded-md border border-border-strong bg-white px-3 py-2.5 text-ink focus:border-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/30"
    >
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-sm text-ink-muted">{{ $hint }}</p>
    @endif
    @error($name)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-danger">{{ $message }}</p>
    @enderror
</div>
