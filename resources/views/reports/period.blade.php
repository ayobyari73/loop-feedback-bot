@extends('reports.layout')

@use('App\Models\Feedback')
@use('App\Services\Jalali')
@php
    $noun = $period->noun();
    $maxPoints = max(1, ...$people->map(fn ($row) => abs($row['summary']['points']))->all() ?: [1]);
    $delta = function (int $now, int $before) use ($noun) {
        if ($before === 0) {
            return $now > 0 ? ['جدید', 'up'] : ['بدون تغییر', ''];
        }
        $percent = (int) round(($now - $before) / $before * 100);

        return [($percent >= 0 ? '↑ ' : '↓ ').abs($percent).'٪ نسبت به '.$noun.' قبل', $percent >= 0 ? 'up' : 'down'];
    };
@endphp

@section('title', 'داشبورد فیدبک لوپ — '.$period->title)

@section('content')
<header>
    <h1>داشبورد فیدبک لوپ — {{ $period->title }}</h1>
    <p class="sub">بازه: {{ $period->range() }} · {{ $activeMembers }} لوپر در لیست</p>
</header>

<section class="grid" aria-label="خلاصه">
    @php [$text, $class] = $delta($totals['feedback'], $previousTotals['feedback']); @endphp
    <div class="card tile"><div class="label">فیدبک‌های این {{ $noun }}</div><div class="value">{{ $totals['feedback'] }}</div><div class="delta {{ $class }}">{{ $text }}</div></div>
    <div class="card tile"><div class="label">لوپرهایی که فیدبک دادند</div><div class="value">{{ $totals['givers'] }}</div><div class="delta">از {{ $activeMembers }} نفر</div></div>
    <div class="card tile"><div class="label">لوپرهایی که فیدبک گرفتند</div><div class="value">{{ $totals['receivers'] }}</div><div class="delta">از {{ $activeMembers }} نفر</div></div>
    @php [$text, $class] = $delta($totals['kinds']['negative'], $previousTotals['kinds']['negative']); @endphp
    <div class="card tile"><div class="label">⚠️ فیدبک منفی</div><div class="value">{{ $totals['kinds']['negative'] }}</div><div class="delta">{{ $text }}</div></div>
</section>

<section class="grid-2">
    <div class="card">
        <h2>چهار شاخص</h2>
        <p class="note">تعداد فیدبک‌های ثبت‌شده در این {{ $noun }} برای هر شاخص</p>
        @include('reports.partials.kind-bars', ['counts' => $totals['kinds']])
    </div>
    <div class="card">
        <h2>روند {{ $period->type === 'month' ? 'روزانه' : 'هفتگی' }}</h2>
        <p class="note">تعداد فیدبک در هر {{ $period->type === 'month' ? 'روز' : 'هفته' }} — از چپ اول {{ $noun }} تا راست آخر {{ $noun }}</p>
        @include('reports.partials.columns', ['points' => $timeline, 'unit' => 'فیدبک'])
    </div>
</section>

<section class="card">
    <h2>رتبه‌بندی لوپرها</h2>
    <p class="note">مرتب بر اساس امتیاز این {{ $noun }}. «از / به چند نفر» نشان می‌دهد امتیاز از چند نفر مختلف آمده.</p>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>لوپر</th><th class="num">⭐ امتیاز</th>
                @foreach (Feedback::SHORT_KINDS as $kind => $label)<th class="num" title="گرفته">📥 {{ $label }}</th>@endforeach
                <th class="num">📤 داده</th><th class="num">از چند نفر</th><th class="num">به چند نفر</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($people as $i => $row)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td>{{ $row['member']->displayName() }}@unless ($row['member']->active) <span class="chip">حذف‌شده</span>@endunless</td>
                <td class="num">@include('reports.partials.points', ['points' => $row['summary']['points'], 'maxPoints' => $maxPoints])</td>
                @foreach (array_keys(Feedback::KINDS) as $kind)<td class="num">{{ $row['summary']['received'][$kind] }}</td>@endforeach
                <td class="num">{{ array_sum($row['summary']['given']) }}</td>
                <td class="num">{{ $row['summary']['from_people'] }}</td>
                <td class="num">{{ $row['summary']['to_people'] }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="empty">لوپری در لیست نیست.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</section>

<section class="grid-2">
    <div class="card">
        <h2>بیشترین تبادل‌ها</h2>
        <p class="note">چه کسی بیشترین بار ثبت کرده از چه کسی ارزش گرفته</p>
        @if (count($pairs))
            <div class="table-wrap"><table>
                <thead><tr><th>ثبت‌کننده</th><th>برای</th><th class="num">تعداد</th></tr></thead>
                <tbody>
                @foreach ($pairs as $pair)
                    <tr><td>{{ $pair['from']->displayName() }}</td><td>{{ $pair['to']->displayName() }}</td><td class="num">{{ $pair['count'] }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <p class="empty">در این {{ $noun }} فیدبکی ثبت نشده.</p>
        @endif
    </div>
    <div class="card">
        <h2>⚠️ فیدبک‌های منفی</h2>
        <p class="note">برای پیگیری و حل‌وفصل</p>
        @forelse ($negatives as $item)
            <p><b>{{ $item->from->displayName() }}</b> ← درباره‌ی <b>{{ $item->to->displayName() }}</b> · {{ Jalali::format($item->created_at) }}<br><span class="text">«{{ $item->description }}»</span></p>
        @empty
            <p class="empty">فیدبک منفی‌ای ثبت نشده.</p>
        @endforelse
    </div>
</section>

<section class="card">
    <h2>همه‌ی فیدبک‌های این {{ $noun }} ({{ $feedback->count() }})</h2>
    <details>
        <summary>نمایش جدول کامل</summary>
        <div class="table-wrap"><table>
            <thead><tr><th>تاریخ</th><th>ثبت‌کننده</th><th>برای</th><th>شاخص</th><th>متن</th></tr></thead>
            <tbody>
            @forelse ($feedback as $item)
                <tr>
                    <td class="num">{{ Jalali::format($item->created_at) }}</td>
                    <td>{{ $item->from->displayName() }}</td>
                    <td>{{ $item->to->displayName() }}</td>
                    <td><span class="swatch" style="background: var(--k-{{ $item->kind }})"></span>{{ Feedback::SHORT_KINDS[$item->kind] }}</td>
                    <td class="text">{{ $item->description }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">فیدبکی ثبت نشده.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </details>
</section>

<section class="card">
    <h2>قانون امتیازدهی فعلی</h2>
    <p class="note">امتیازها همیشه با همین قانون حساب می‌شوند (روی فیدبک‌های قبلی هم)</p>
    <div class="table-wrap"><table>
        <thead><tr><th>شاخص</th><th class="num">دهنده</th><th class="num">گیرنده</th></tr></thead>
        <tbody>
        @foreach (Feedback::SHORT_KINDS as $kind => $label)
            <tr><td>{{ $label }}</td><td class="num">{{ $rules[$kind]['giver'] }}</td><td class="num">{{ $rules[$kind]['receiver'] }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
</section>
@endsection
