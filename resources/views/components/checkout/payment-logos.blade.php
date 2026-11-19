@props(['names', 'path' => 'images/payments'])

{{--
    Payment brand logos, one file per name in public/{path}/ (visa, mastercard, stripe), an SVG if
    there is one, otherwise a PNG. Only the logos that exist are shown, so a missing file never
    leaves a broken image. The artwork must be unaltered (docs/cart.md, Payment logos).
--}}
@php
    $found = [];

    foreach ($names as $name) {
        foreach (['svg', 'png'] as $extension) {
            if (is_file(public_path($path.'/'.$name.'.'.$extension))) {
                $found[$name] = $path.'/'.$name.'.'.$extension;

                break;
            }
        }
    }
@endphp

@if ($found !== [])
    <ul {{ $attributes->class('flex flex-wrap items-center justify-center gap-3') }} data-payment-logos>
        @foreach ($found as $name => $file)
            <li class="rounded border border-border bg-white px-2 py-1">
                <img src="{{ asset($file) }}" alt="{{ \Illuminate\Support\Str::headline($name) }}" height="24" class="h-6 w-auto" data-payment-logo="{{ $name }}">
            </li>
        @endforeach
    </ul>
@endif
