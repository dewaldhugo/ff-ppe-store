@props(['id', 'title', 'labels', 'values', 'type' => 'bar', 'money' => false])

{{--
    A bar or line chart drawn as an SVG, no script: one value for each label. Money values are whole pence, shown in pounds.
    The geometry works on pounds as a plain number for drawing only; the figures are the pence, formatted.
--}}
@php
    $width = 900;
    $height = 280;
    $left = 64;
    $right = 16;
    $top = 12;
    $bottom = 36;
    $divisor = $money ? 100 : 1;

    $labels = array_values($labels);
    $points = array_map(fn ($value): float => $value / $divisor, array_values($values));
    $high = max(0.0, ...($points ?: [0.0]));
    $low = min(0.0, ...($points ?: [0.0]));

    // A tick step of 1, 2, 2.5 or 5 times a power of ten, so the axis has about four steps.
    $range = max($high - $low, 1);
    $magnitude = 10 ** floor(log10($range / 4));
    $step = $magnitude;
    foreach ([1, 2, 2.5, 5, 10] as $multiple) {
        $step = $magnitude * $multiple;
        if ($range / $step <= 4) {
            break;
        }
    }
    $axisHigh = ceil($high / $step) * $step;
    $axisLow = floor($low / $step) * $step;
    if ($axisHigh === $axisLow) {
        $axisHigh = $axisLow + $step;
    }

    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $count = max(1, count($points));
    $y = fn (float $value): float => $top + $plotHeight * (1 - ($value - $axisLow) / ($axisHigh - $axisLow));
    $slot = $plotWidth / $count;
    $zero = $y(0.0);

    $format = function (float $value) use ($money): string {
        $text = number_format($value, $value == floor($value) ? 0 : 1);

        return $money ? '£'.$text : $text;
    };

    $ticks = [];
    for ($tick = $axisLow; $tick <= $axisHigh + $step / 1000; $tick += $step) {
        $ticks[] = $tick;
    }

    $line = [];
    foreach ($points as $index => $point) {
        $line[] = round($left + $slot * ($index + 0.5), 1).','.round($y($point), 1);
    }
@endphp

<x-filament::section :heading="$title">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $title }}" data-chart="{{ $id }}" data-chart-type="{{ $type }}" style="display: block; width: 100%; height: auto; max-height: 20rem;">
        @foreach ($ticks as $tick)
            <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ round($y($tick), 1) }}" y2="{{ round($y($tick), 1) }}" stroke="currentColor" stroke-opacity="0.12" />
            <text x="{{ $left - 8 }}" y="{{ round($y($tick), 1) + 4 }}" text-anchor="end" font-size="12" fill="currentColor" fill-opacity="0.7">{{ $format($tick) }}</text>
        @endforeach

        @if ($type === 'line' && count($points) > 1)
            <polyline points="{{ implode(' ', $line) }}" fill="none" stroke="var(--primary-600)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
        @endif

        @foreach ($points as $index => $point)
            @php($centre = $left + $slot * ($index + 0.5))
            @if ($type === 'line')
                <circle cx="{{ round($centre, 1) }}" cy="{{ round($y($point), 1) }}" r="4" fill="var(--primary-600)" data-point>
                    <title>{{ $labels[$index] }}: {{ $format($point) }}</title>
                </circle>
            @else
                @php($barWidth = min(90, $slot * 0.7))
                <rect x="{{ round($centre - $barWidth / 2, 1) }}" y="{{ round(min($y($point), $zero), 1) }}" width="{{ round($barWidth, 1) }}" height="{{ round(abs($zero - $y($point)), 1) }}" fill="var(--primary-600)" data-bar>
                    <title>{{ $labels[$index] }}: {{ $format($point) }}</title>
                </rect>
            @endif

            <text x="{{ round($centre, 1) }}" y="{{ $height - 12 }}" text-anchor="middle" font-size="12" fill="currentColor" fill-opacity="0.7">{{ \Illuminate\Support\Str::limit($labels[$index], $count > 6 ? 12 : 22) }}</text>
        @endforeach
    </svg>
</x-filament::section>
