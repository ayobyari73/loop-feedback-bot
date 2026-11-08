<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title')</title>
<style>
    .viz-root {
        color-scheme: light;
        --page: #f9f9f7;
        --surface-1: #fcfcfb;
        --text-primary: #0b0b0b;
        --text-secondary: #52514e;
        --muted: #898781;
        --grid: #e1e0d9;
        --axis: #c3c2b7;
        --border: rgba(11, 11, 11, 0.10);
        --activity: #6250d6;
        --k-network: #2a78d6;
        --k-knowledge: #eb6834;
        --k-experience: #1baf7a;
        --k-negative: #e34948;
        --up: #006300;
        --down: #d03b3b;
    }
    @media (prefers-color-scheme: dark) {
        :root:where(:not([data-theme="light"])) .viz-root {
            color-scheme: dark;
            --page: #0d0d0d;
            --surface-1: #1a1a19;
            --text-primary: #f0efec;
            --text-secondary: #c3c2b7;
            --grid: #2c2c2a;
            --axis: #383835;
            --border: rgba(255, 255, 255, 0.10);
            --activity: #9085e9;
            --k-network: #3987e5;
            --k-knowledge: #d95926;
            --k-experience: #199e70;
            --k-negative: #e66767;
            --up: #0ca30c;
            --down: #e66767;
        }
    }
    :root[data-theme="dark"] .viz-root {
        color-scheme: dark;
        --page: #0d0d0d;
        --surface-1: #1a1a19;
        --text-primary: #f0efec;
        --text-secondary: #c3c2b7;
        --grid: #2c2c2a;
        --axis: #383835;
        --border: rgba(255, 255, 255, 0.10);
        --activity: #9085e9;
        --k-network: #3987e5;
        --k-knowledge: #d95926;
        --k-experience: #199e70;
        --k-negative: #e66767;
        --up: #0ca30c;
        --down: #e66767;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; }
    body.viz-root {
        background: var(--page);
        color: var(--text-primary);
        font-family: Vazirmatn, "Segoe UI", Tahoma, system-ui, -apple-system, sans-serif;
        line-height: 1.7;
        padding: 24px 16px 48px;
    }
    main { max-width: 1080px; margin: 0 auto; }
    header { margin-bottom: 24px; }
    h1 { font-size: 1.6rem; margin: 0 0 4px; }
    h2 { font-size: 1.1rem; margin: 0 0 4px; }
    .sub { color: var(--text-secondary); margin: 0; }
    .note { color: var(--muted); font-size: .85rem; margin: 4px 0 12px; }
    .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); margin-bottom: 16px; }
    .grid-2 { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-bottom: 16px; }
    .card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin-bottom: 16px; min-width: 0; }
    .grid .card, .grid-2 .card { margin-bottom: 0; }
    .tile .label { color: var(--text-secondary); font-size: .9rem; }
    .tile .value { font-size: 2rem; font-weight: 700; line-height: 1.3; }
    .tile .delta { font-size: .85rem; color: var(--text-secondary); }
    .delta.up { color: var(--up); }
    .delta.down { color: var(--down); }
    .bars { display: grid; gap: 10px; margin-top: 12px; }
    .bar-row { display: grid; grid-template-columns: 7.5rem 1fr 3rem; align-items: center; gap: 10px; }
    .bar-label { color: var(--text-secondary); white-space: nowrap; }
    .bar-track { height: 14px; display: flex; }
    .bar { height: 14px; border-radius: 4px 0 0 4px; min-width: 2px; }
    .bar-value { font-variant-numeric: tabular-nums; text-align: left; }
    .chart { width: 100%; height: auto; display: block; margin-top: 8px; }
    .chart text { fill: var(--muted); font-size: 11px; font-family: inherit; }
    .chart .value-label { fill: var(--text-secondary); }
    .chart .gridline { stroke: var(--grid); stroke-width: 1; }
    .chart .baseline { stroke: var(--axis); stroke-width: 1; }
    .chart .hit { fill: transparent; }
    .chart .hit:hover + .mark, .chart g:hover .mark { opacity: .8; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    th, td { padding: 8px 10px; border-bottom: 1px solid var(--grid); text-align: right; vertical-align: top; }
    th { color: var(--text-secondary); font-weight: 600; white-space: nowrap; }
    td.num, th.num { font-variant-numeric: tabular-nums; text-align: left; white-space: nowrap; }
    .points { display: flex; align-items: center; gap: 8px; justify-content: flex-end; }
    .points .bar { height: 10px; }
    .chip { display: inline-block; padding: 0 8px; border-radius: 999px; font-size: .8rem; border: 1px solid var(--border); white-space: nowrap; }
    .swatch { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-left: 6px; vertical-align: middle; }
    .text { white-space: pre-wrap; }
    .neg { color: var(--down); }
    details summary { cursor: pointer; color: var(--text-secondary); margin-bottom: 8px; }
    .empty { color: var(--muted); }
    footer { color: var(--muted); font-size: .85rem; margin-top: 24px; }
    @media (max-width: 520px) {
        .bar-row { grid-template-columns: 6rem 1fr 2.5rem; }
        .tile .value { font-size: 1.6rem; }
    }
</style>
</head>
<body class="viz-root">
<main>
@yield('content')
<footer>
    <p>این اعداد کمک تصمیم‌اند، نه خود تصمیم. فیدبک‌ها ناشناس نیستند و فیدبک‌های حذف‌شده توسط ادمین‌ها در گزارش نمی‌آیند.</p>
    <p>تهیه‌شده توسط ربات فیدبک لوپ · {{ $generatedAt }}</p>
</footer>
</main>
</body>
</html>
