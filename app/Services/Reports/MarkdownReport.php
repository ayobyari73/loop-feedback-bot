<?php

namespace App\Services\Reports;

use App\Models\Feedback;
use App\Models\Member;
use App\Services\Jalali;
use Illuminate\Support\Collection;

/**
 * Plain-text (Markdown) versions of the reports, written to be pasted into an AI assistant for analysis:
 * a short data guide, the scoring rules, summary tables and every feedback text, one row each.
 */
class MarkdownReport
{
    /**
     * @param  array<string, mixed>  $data  From ReportData::forPeriod().
     */
    public function period(array $data): string
    {
        /** @var Period $period */
        $period = $data['period'];
        $totals = $data['totals'];
        $previous = $data['previousTotals'];

        $lines = [
            "# گزارش فیدبک لوپ — {$period->title}",
            '',
            "- بازه: {$period->range()} (تقویم شمسی، ساعت تهران)",
            "- زمان تهیه: {$data['generatedAt']}",
            "- لوپرهای فعال در لیست: {$data['activeMembers']}",
            '',
            ...$this->guide(),
            ...$this->rules($data['rules']),
            '## خلاصه',
            '',
            $this->row(['شاخص', "این {$period->noun()}", "{$period->noun()} قبل ({$data['previous']->title})"]),
            $this->row(['---', '---:', '---:']),
            ...array_map(fn (string $kind) => $this->row([Feedback::SHORT_KINDS[$kind]." ({$kind})", $totals['kinds'][$kind], $previous['kinds'][$kind]]), array_keys(Feedback::KINDS)),
            $this->row(['کل فیدبک‌ها', $totals['feedback'], $previous['feedback']]),
            '',
            "- لوپرهایی که فیدبک دادند: {$totals['givers']}",
            "- لوپرهایی که فیدبک گرفتند: {$totals['receivers']}",
            '',
            "## روند {$this->timelineUnit($period)}",
            '',
            $this->row([$period->type === 'month' ? 'روز' : 'هفته (شروع)', 'تعداد فیدبک']),
            $this->row(['---', '---:']),
            ...array_map(fn (array $point) => $this->row([$point['label'], $point['count']]), $data['timeline']),
            '',
            '## لوپرها (مرتب بر اساس امتیاز)',
            '',
            ...$this->peopleTable($data['people']),
            '',
            '## بیشترین تبادل‌ها (ثبت‌کننده → کسی که ارزش داده)',
            '',
            $this->row(['ثبت‌کننده', 'برای', 'تعداد', ...array_values(Feedback::SHORT_KINDS)]),
            $this->row(['---', '---', '---:', '---:', '---:', '---:', '---:']),
            ...array_map(fn (array $pair) => $this->row([$this->name($pair['from']), $this->name($pair['to']), $pair['count'], ...array_values($pair['kinds'])]), $data['pairs']),
            '',
            "## همه‌ی فیدبک‌های این {$period->noun()} ({$data['feedback']->count()})",
            '',
            ...$this->feedbackTable($data['feedback']),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $data  From ReportData::forMember().
     */
    public function member(array $data): string
    {
        /** @var Member $member */
        $member = $data['member'];
        $summaries = $data['summaries'];

        $lines = [
            '# گزارش شخصی فیدبک لوپ — '.$this->name($member),
            '',
            '- یوزرنیم تلگرام: @'.$member->username,
            '- نقش: '.($member->is_admin ? 'ادمین' : 'لوپر').($member->active ? '' : ' (از لیست حذف شده)'),
            "- زمان تهیه: {$data['generatedAt']}",
            '- رتبه در '.$data['season']->title.': '.($data['rank'] ? "{$data['rank']} از {$data['rankedCount']}" : 'ندارد'),
            '',
            ...$this->guide(),
            ...$this->rules($data['rules']),
            '## امتیاز و شاخص‌ها',
            '',
            $this->row(['', $data['month']->title, 'این فصل', 'کل دوره']),
            $this->row(['---', '---:', '---:', '---:']),
            $this->row(['⭐ امتیاز', $summaries['month']['points'], $summaries['season']['points'], $summaries['total']['points']]),
        ];

        foreach (['received' => '📥 گرفته', 'given' => '📤 داده'] as $side => $label) {
            foreach (Feedback::SHORT_KINDS as $kind => $kindLabel) {
                $lines[] = $this->row(["{$label} — {$kindLabel}", $summaries['month'][$side][$kind], $summaries['season'][$side][$kind], $summaries['total'][$side][$kind]]);
            }
        }
        $lines[] = $this->row(['👥 از چند نفر مختلف گرفته', $summaries['month']['from_people'], $summaries['season']['from_people'], $summaries['total']['from_people']]);
        $lines[] = $this->row(['👥 به چند نفر مختلف داده', $summaries['month']['to_people'], $summaries['season']['to_people'], $summaries['total']['to_people']]);

        $lines = [
            ...$lines,
            '',
            '## روند ۱۲ ماه اخیر',
            '',
            $this->row(['ماه', 'گرفته', 'داده']),
            $this->row(['---', '---:', '---:']),
            ...array_map(fn (array $month) => $this->row([$month['label'], $month['received'], $month['given']]), $data['monthly']),
            '',
            '## افرادی که با آن‌ها تبادل داشته',
            '',
            $this->row(['لوپر', 'از او گرفته', 'به او داده']),
            $this->row(['---', '---:', '---:']),
            ...array_map(fn (array $row) => $this->row([$this->name($row['member']), $row['received'], $row['given']]), $data['counterparts']),
            '',
            "## فیدبک‌های گرفته ({$data['received']->count()})",
            '',
            ...$this->feedbackTable($data['received']),
            '',
            "## فیدبک‌های داده ({$data['given']->count()})",
            '',
            ...$this->feedbackTable($data['given']),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function guide(): array
    {
        return [
            '## راهنمای داده',
            '',
            '- «لوپ» یک جامعه است که اعضایش (لوپرها) به هم کمک می‌کنند. هر فیدبک یعنی ثبت‌کننده اعلام کرده از طرف مقابل یکی از این چهار مورد را دریافت کرده:',
            '  انتقال شبکه (network: معرفی به آدم‌ها و فرصت‌ها)، انتقال دانش (knowledge)، انتقال تجربه (experience)، یا فیدبک منفی (negative: مشکلی که باید حل‌وفصل شود).',
            '- «گرفته» = فیدبک‌هایی که دیگران برای این شخص ثبت کرده‌اند (یعنی او ارزش داده). «داده» = فیدبک‌هایی که خود او ثبت کرده (یعنی او ارزش گرفته و ثبتش کرده).',
            '- امتیاز = تعداد هر شاخص × قانون امتیازدهی فعلی (جدول پایین). قانون را اونر تعیین می‌کند و روی همه‌ی فیدبک‌ها، از جمله قدیمی‌ها، اعمال می‌شود.',
            '- «از / به چند نفر مختلف» نشان می‌دهد امتیاز از چند آدم متفاوت آمده؛ تکرار زیاد بین دو نفر را از این عدد می‌شود تشخیص داد.',
            '- فیدبک‌ها ناشناس نیستند، محدودیت تکرار ندارند و فیدبک‌های حذف‌شده توسط ادمین در این فایل نیستند.',
            '- این داده‌ها کمک تصمیم‌اند، نه خود تصمیم؛ تصمیم نهایی درباره‌ی لوپرها در پایان هر فصل سه‌ماهه و با مشاهدات ارزیاب‌ها گرفته می‌شود.',
            '',
        ];
    }

    /**
     * @param  array<string, array{giver: int, receiver: int}>  $rules
     * @return list<string>
     */
    private function rules(array $rules): array
    {
        return [
            '## قانون امتیازدهی فعلی',
            '',
            $this->row(['شاخص', 'امتیاز ثبت‌کننده (دهنده)', 'امتیاز کسی که برایش ثبت شده (گیرنده)']),
            $this->row(['---', '---:', '---:']),
            ...array_map(fn (string $kind) => $this->row([Feedback::SHORT_KINDS[$kind], $rules[$kind]['giver'], $rules[$kind]['receiver']]), array_keys(Feedback::KINDS)),
            '',
        ];
    }

    /**
     * @param  Collection<int, array{member: Member, summary: array<string, mixed>}>  $people
     * @return list<string>
     */
    private function peopleTable(Collection $people): array
    {
        $kinds = array_keys(Feedback::KINDS);
        $lines = [
            $this->row(['رتبه', 'نام', 'یوزرنیم', 'امتیاز', ...array_map(fn ($k) => 'گرفته '.$k, $kinds), ...array_map(fn ($k) => 'داده '.$k, $kinds), 'از چند نفر', 'به چند نفر', 'وضعیت']),
            $this->row(array_merge(['---:', '---', '---'], array_fill(0, 11, '---:'), ['---'])),
        ];

        foreach ($people->values() as $i => $row) {
            $summary = $row['summary'];
            $lines[] = $this->row([
                $i + 1,
                $this->name($row['member']),
                '@'.$row['member']->username,
                $summary['points'],
                ...array_map(fn ($k) => $summary['received'][$k], $kinds),
                ...array_map(fn ($k) => $summary['given'][$k], $kinds),
                $summary['from_people'],
                $summary['to_people'],
                ($row['member']->is_admin ? 'ادمین' : 'لوپر').($row['member']->active ? '' : '، حذف‌شده'),
            ]);
        }

        return $lines;
    }

    /**
     * @param  Collection<int, Feedback>  $feedback
     * @return list<string>
     */
    private function feedbackTable(Collection $feedback): array
    {
        if ($feedback->isEmpty()) {
            return ['فیدبکی ثبت نشده.'];
        }

        return [
            $this->row(['#', 'تاریخ', 'ثبت‌کننده', 'برای', 'شاخص', 'متن']),
            $this->row(['---:', '---', '---', '---', '---', '---']),
            ...$feedback->values()->map(fn (Feedback $item, int $i) => $this->row([
                $i + 1,
                Jalali::format($item->created_at),
                $this->name($item->from),
                $this->name($item->to),
                $item->kind,
                $item->description,
            ]))->all(),
        ];
    }

    private function timelineUnit(Period $period): string
    {
        return $period->type === 'month' ? 'روزانه' : 'هفتگی';
    }

    private function name(Member $member): string
    {
        return $member->name ?: '@'.$member->username;
    }

    /**
     * One Markdown table row; pipes and line breaks inside cells are neutralised.
     *
     * @param  list<int|string>  $cells
     */
    private function row(array $cells): string
    {
        return '| '.implode(' | ', array_map(fn ($cell) => str_replace(['|', "\r\n", "\n", "\r"], ['\\|', ' ', ' ', ' '], (string) $cell), $cells)).' |';
    }
}
