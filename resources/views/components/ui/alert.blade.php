@props(['variant' => 'status'])

@if ($variant === 'error')
    <div role="alert" {{ $attributes->class('rounded-md border border-danger bg-surface-muted p-3 text-sm text-danger') }}>{{ $slot }}</div>
@else
    <div role="status" {{ $attributes->class('rounded-md border border-success bg-surface-muted p-4 text-ink') }}>{{ $slot }}</div>
@endif
