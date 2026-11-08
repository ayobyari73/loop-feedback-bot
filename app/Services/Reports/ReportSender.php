<?php

namespace App\Services\Reports;

use App\Models\Member;
use App\Services\Jalali;
use App\Services\Telegram;

/**
 * Renders a report as an HTML dashboard and a Markdown export and sends both files to a chat.
 */
class ReportSender
{
    public function __construct(
        private Telegram $telegram,
        private ReportData $data,
        private MarkdownReport $markdown,
    ) {}

    public function sendPeriod(int $chatId, Period $period): bool
    {
        $data = $this->data->forPeriod($period);

        return $this->send($chatId, "loop-{$period->key}", view('reports.period', $data)->render(), $this->markdown->period($data), $period->title);
    }

    public function sendMember(int $chatId, Member $member): bool
    {
        $data = $this->data->forMember($member);
        $slug = 'loop-person-'.$member->username.'-'.str_replace('/', '-', Jalali::format(now()));

        return $this->send($chatId, $slug, view('reports.personal', $data)->render(), $this->markdown->member($data), 'گزارش شخصی '.$member->label());
    }

    private function send(int $chatId, string $baseName, string $html, string $markdown, string $title): bool
    {
        $dashboard = $this->telegram->sendDocument($chatId, "{$baseName}.html", $html,
            "📊 <b>داشبورد {$title}</b>\nفایل رو باز کن تا نمودارها و جدول‌ها توی مرورگر نمایش داده بشن.");
        $export = $this->telegram->sendDocument($chatId, "{$baseName}.md", $markdown,
            "📄 <b>فایل متنی {$title}</b>\nاین فایل رو به ChatGPT یا Claude بده و ازش بخواه تحلیل کنه؛ راهنمای داده داخل خود فایله.");

        return $dashboard && $export;
    }
}
