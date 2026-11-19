@props(['hexes' => []])

{{-- A colour swatch. Several colours split it into equal vertical bands, left to right. Data-driven colours,
     so they are set inline; an empty list leaves the neutral fill. --}}
@php
    $hexes = array_values(array_filter((array) $hexes));
    $count = count($hexes);
    $style = null;

    if ($count === 1) {
        $style = 'background-color: #'.$hexes[0];
    } elseif ($count > 1) {
        $stops = [];

        foreach ($hexes as $i => $hex) {
            $stops[] = '#'.$hex.' '.round($i * 100 / $count, 2).'% '.round(($i + 1) * 100 / $count, 2).'%';
        }

        $style = 'background-image: linear-gradient(to right, '.implode(', ', $stops).')';
    }
@endphp
<span {{ $attributes->class(['inline-block shrink-0 rounded-full border border-border-strong', 'bg-disabled-surface' => $count === 0]) }} @if ($style) style="{{ $style }}" @endif></span>
