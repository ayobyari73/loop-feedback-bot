{{-- Horizontal bars, one per indicator. Expects $counts (kind => count). --}}
@php $max = max(1, ...array_values($counts)); @endphp
<div class="bars" role="list">
    @foreach (\App\Models\Feedback::SHORT_KINDS as $kind => $label)
        <div class="bar-row" role="listitem" title="{{ $label }}: {{ $counts[$kind] }}">
            <span class="bar-label">{{ $label }}</span>
            <span class="bar-track"><span class="bar" style="width: {{ round($counts[$kind] / $max * 100, 1) }}%; background: var(--k-{{ $kind }});"></span></span>
            <span class="bar-value">{{ $counts[$kind] }}</span>
        </div>
    @endforeach
</div>
