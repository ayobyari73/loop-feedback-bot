{{--
    Column chart over time, oldest on the left. Expects $points (list of [label, count]) and $unit (e.g. «فیدبک»).
    Every column has a full-height hover target with a tooltip; only the highest column is labelled directly.
--}}
@php
    $width = 640;
    $height = 190;
    $top = 18;
    $bottom = 24;
    $left = 28;
    $plot = $height - $top - $bottom;
    $count = max(1, count($points));
    $slot = ($width - $left) / $count;
    $barWidth = max(2, min(36, $slot - 2));
    $max = max(1, ...array_column($points, 'count'));
    $peak = array_search(max(array_column($points, 'count') ?: [0]), array_column($points, 'count'), true);
    $labelEvery = (int) ceil($count / 10);
    $ticks = array_unique([0, (int) ceil($max / 2), $max]);
@endphp
<svg class="chart" viewBox="0 0 {{ $width }} {{ $height }}" direction="ltr" role="img" aria-label="{{ $unit }} در طول زمان">
    @foreach ($ticks as $tick)
        @php $y = $top + $plot - $tick / $max * $plot; @endphp
        <line class="{{ $tick === 0 ? 'baseline' : 'gridline' }}" x1="{{ $left }}" x2="{{ $width }}" y1="{{ $y }}" y2="{{ $y }}" />
        <text x="{{ $left - 6 }}" y="{{ $y + 4 }}" text-anchor="end">{{ $tick }}</text>
    @endforeach
    @foreach ($points as $i => $point)
        @php
            $x = $left + $i * $slot + ($slot - $barWidth) / 2;
            $h = $point['count'] / $max * $plot;
            $y = $top + $plot - $h;
            $base = $top + $plot;
            $r = min(4, $barWidth / 2, $h);
        @endphp
        <g>
            <title>{{ $point['label'] }}: {{ $point['count'] }} {{ $unit }}</title>
            <rect class="hit" x="{{ $left + $i * $slot }}" y="{{ $top }}" width="{{ $slot }}" height="{{ $plot }}" />
            @if ($point['count'] > 0)
                <path class="mark" fill="var(--activity)" d="M{{ $x }},{{ $base }} V{{ $y + $r }} Q{{ $x }},{{ $y }} {{ $x + $r }},{{ $y }} H{{ $x + $barWidth - $r }} Q{{ $x + $barWidth }},{{ $y }} {{ $x + $barWidth }},{{ $y + $r }} V{{ $base }} Z" />
            @endif
            @if ($i === $peak && $point['count'] > 0)
                <text class="value-label" x="{{ $x + $barWidth / 2 }}" y="{{ $y - 5 }}" text-anchor="middle">{{ $point['count'] }}</text>
            @endif
            @if (($count - 1 - $i) % $labelEvery === 0)
                <text x="{{ $left + $i * $slot + $slot / 2 }}" y="{{ $height - 6 }}" text-anchor="middle">{{ $point['short'] ?? $point['label'] }}</text>
            @endif
        </g>
    @endforeach
</svg>
