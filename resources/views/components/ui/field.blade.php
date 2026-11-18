@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'optional' => false,
    'autocomplete' => null,
    'maxlength' => 255,
    'hint' => null,
])

{{--
    A labelled form field with its validation error, matching the Contact form markup.
    Passwords are never sent back to the page. The value comes from old input, then $value.
--}}
@php
    $id = 'field-'.str_replace('_', '-', $name);
    $errorId = $id.'-error';
    $hintId = $id.'-hint';
    $describedBy = trim(($errors->has($name) ? $errorId : '').' '.($hint ? $hintId : ''));
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
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        maxlength="{{ $maxlength }}"
        @if (! $optional) required @endif
        @if ($errors->has($name)) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        class="mt-1 block w-full rounded-md border border-border-strong bg-white px-3 py-2.5 text-ink focus:border-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/30"
    >
    @if ($hint)
        <p id="{{ $hintId }}" class="mt-1 text-sm text-ink-muted">{{ $hint }}</p>
    @endif
    @error($name)
        <p id="{{ $errorId }}" class="mt-1 text-sm text-danger">{{ $message }}</p>
    @enderror
</div>
