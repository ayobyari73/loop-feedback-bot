@extends('reports.layout')

@use('App\Models\Feedback')
@use('App\Services\Jalali')
@php
    $name = $member->displayName();
    $role = $member->is_admin ? 'ادمین' : 'لوپر';
    $receivedTrend = array_map(fn ($row) => ['label' => $row['label'], 'short' => $row['short'], 'count' => $row['received']], $monthly);
    $givenTrend = array_map(fn ($row) => ['label' => $row['label'], 'short' => $row['short'], 'count' => $row['given']], $monthly);
@endphp

@section('title', 'گزارش شخصی — '.$name)

@section('content')
<header>
    <h1>گزارش شخصی — {{ $name }}</h1>
    <p class="sub"><bdi dir="ltr">{{ '@'.$member->username }}</bdi> · {{ $role }}@unless ($member->active) · حذف‌شده از لیست@endunless</p>
    <p class="sub">{{ $season->title }}</p>
</header>

<section class="grid" aria-label="خلاصه">
    <div class="card tile"><div class="label">⭐ امتیاز {{ $month->title }}</div><div class="value">{{ $summaries['month']['points'] }}</div></div>
    <div class="card tile"><div class="label">⭐ امتیاز این فصل</div><div class="value">{{ $summaries['season']['points'] }}</div><div class="delta">{{ $rank ? "رتبه‌ی {$rank} از {$rankedCount}" : 'بدون رتبه' }}</div></div>
    <div class="card tile"><div class="label">⭐ امتیاز کل دوره</div><div class="value">{{ $summaries['total']['points'] }}</div></div>
    <div class="card tile"><div class="label">👥 افراد مختلف</div><div class="value">{{ $summaries['total']['from_people'] }} / {{ $summaries['total']['to_people'] }}</div><div class="delta">فیدبک گرفته از / داده به</div></div>
</section>

<section class="grid-2">
    <div class="card">
        <h2>📥 گرفته (کل دوره)</h2>
        <p class="note">فیدبک‌هایی که دیگران برای {{ $name }} ثبت کرده‌اند</p>
        @include('reports.partials.kind-bars', ['counts' => $summaries['total']['received']])
    </div>
    <div class="card">
        <h2>📤 داده (کل دوره)</h2>
        <p class="note">فیدبک‌هایی که {{ $name }} برای دیگران ثبت کرده</p>
        @include('reports.partials.kind-bars', ['counts' => $summaries['total']['given']])
    </div>
</section>

<section class="card">
    <h2>چهار شاخص در سه بازه</h2>
    <div class="table-wrap"><table>
        <thead>
            <tr><th rowspan="2">شاخص</th><th colspan="3">📥 گرفته</th><th colspan="3">📤 داده</th></tr>
            <tr><th class="num">{{ $month->title }}</th><th class="num">این فصل</th><th class="num">کل</th><th class="num">{{ $month->title }}</th><th class="num">این فصل</th><th class="num">کل</th></tr>
        </thead>
        <tbody>
        @foreach (Feedback::SHORT_KINDS as $kind => $label)
            <tr>
                <td><span class="swatch" style="background: var(--k-{{ $kind }})"></span>{{ $label }}</td>
                @foreach (['received', 'given'] as $side)
                    @foreach (['month', 'season', 'total'] as $range)
                        <td class="num">{{ $summaries[$range][$side][$kind] }}</td>
                    @endforeach
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table></div>
</section>

<section class="grid-2">
    <div class="card">
        <h2>روند گرفته‌ها</h2>
        <p class="note">فیدبک‌های گرفته در هر ماه — ۱۲ ماه اخیر، از چپ قدیمی‌ترین</p>
        @include('reports.partials.columns', ['points' => $receivedTrend, 'unit' => 'فیدبک گرفته'])
    </div>
    <div class="card">
        <h2>روند داده‌ها</h2>
        <p class="note">فیدبک‌های ثبت‌شده در هر ماه — ۱۲ ماه اخیر، از چپ قدیمی‌ترین</p>
        @include('reports.partials.columns', ['points' => $givenTrend, 'unit' => 'فیدبک داده'])
    </div>
</section>

<section class="card">
    <h2>با چه کسانی تبادل داشته</h2>
    @if (count($counterparts))
        <div class="table-wrap"><table>
            <thead><tr><th>لوپر</th><th class="num">📥 از او گرفته</th><th class="num">📤 به او داده</th></tr></thead>
            <tbody>
            @foreach ($counterparts as $row)
                <tr><td>{{ $row['member']->displayName() }}</td><td class="num">{{ $row['received'] }}</td><td class="num">{{ $row['given'] }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    @else
        <p class="empty">هنوز با کسی تبادل فیدبک نداشته.</p>
    @endif
</section>

@foreach (['received' => ['📥 فیدبک‌های گرفته', 'از'], 'given' => ['📤 فیدبک‌های داده', 'برای']] as $side => [$title, $direction])
    <section class="card">
        <h2>{{ $title }} ({{ ${$side}->count() }})</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>تاریخ</th><th>{{ $direction }}</th><th>شاخص</th><th>متن</th></tr></thead>
            <tbody>
            @forelse (${$side} as $item)
                @php $other = $side === 'received' ? $item->from : $item->to; @endphp
                <tr>
                    <td class="num">{{ Jalali::format($item->created_at) }}</td>
                    <td>{{ $other->displayName() }}</td>
                    <td><span class="swatch" style="background: var(--k-{{ $item->kind }})"></span>{{ Feedback::SHORT_KINDS[$item->kind] }}</td>
                    <td class="text">{{ $item->description }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">فیدبکی نیست.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
@endforeach
@endsection
