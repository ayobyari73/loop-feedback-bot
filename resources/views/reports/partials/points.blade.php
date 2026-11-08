{{-- Points with an inline bar scaled to $maxPoints; negative points are drawn in red. --}}
<span class="points">
    <span class="bar" style="width: {{ round(abs($points) / max(1, $maxPoints) * 80) }}px; background: {{ $points < 0 ? 'var(--k-negative)' : 'var(--activity)' }};"></span>
    <b class="{{ $points < 0 ? 'neg' : '' }}">{{ $points }}</b>
</span>
