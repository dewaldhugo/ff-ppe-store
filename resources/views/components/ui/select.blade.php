@props([
    'name',
    'label',
    'options' => [],
    'value' => null,
    'placeholder' => 'Choose one',
    'optional' => false,
    'autocomplete' => null,
])

{{-- A labelled select with its validation error, matching x-ui.field. The value comes from old input, then $value. --}}
@php
    $id = 'field-'.str_replace('_', '-', $name);
    $errorId = $id.'-error';
    $selected = (string) old($name, $value);
@endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="block text-sm font-semibold text-ink">
        {{ $label }}
        @if ($optional)
            <span class="font-normal text-ink-muted">(optional)</span>
        @endif
    </label>
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if (! $optional) required @endif
        @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif
        class="mt-1 block w-full rounded-md border border-border-strong bg-white px-3 py-2.5 text-ink focus:border-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/30"
    >
        <option value="">{{ $placeholder }}</option>
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($selected === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @error($name)
        <p id="{{ $errorId }}" class="mt-1 text-sm text-danger">{{ $message }}</p>
    @enderror
</div>
