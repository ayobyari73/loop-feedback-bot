<?php

namespace App\Services\Bot;

use App\Models\Feedback;
use App\Models\Member;
use App\Services\ProfileStats;
use App\Services\Season;
use App\Services\Telegram;

/**
 * A looper's profile (four indicators, received and given, season and all time, weekly trend) and the
 * feedback lists behind it. The looper sees their own; admins and the owner see everyone's and can delete feedback.
 */
class ProfileFlow
{
    private const LIST_SIZE = 8;

    public function __construct(
        private Telegram $telegram,
        private ProfileStats $stats,
        private Access $access,
        private Ui $ui,
    ) {}

    public function showOwn(Actor $actor): void
    {
        $member = $actor->member;
        $this->telegram->send($actor->chatId, $this->render($member), $this->profileButtons($actor, $member));
    }

    /**
     * Callbacks: pf:{member}, fl:{member}:{r|g}:{page}, del:{feedback}:{member}:{r|g}:{page}, dly:{same}.
     */
    public function handleCallback(Actor $actor, string $action, string $value, int $messageId): void
    {
        $parts = explode(':', $value);
        $subject = Member::find((int) ($action === 'del' || $action === 'dly' ? ($parts[1] ?? 0) : $parts[0]));

        if (! $subject || ! $this->access->canSeeProfile($actor, $subject)) {
            return;
        }

        if ($action === 'pf') {
            $this->telegram->edit($actor->chatId, $messageId, $this->render($subject), $this->profileButtons($actor, $subject));
        } elseif ($action === 'fl') {
            $this->showList($actor, $subject, ($parts[1] ?? 'r') === 'g' ? 'g' : 'r', (int) ($parts[2] ?? 0), $messageId);
        } elseif ($actor->isAdmin && ($action === 'del' || $action === 'dly')) {
            $this->delete($actor, $subject, (int) $parts[0], ($parts[2] ?? 'r') === 'g' ? 'g' : 'r', (int) ($parts[3] ?? 0), $action === 'dly', $messageId);
        }
    }

    public function render(Member $member): string
    {
        $stats = $this->stats->forMember($member);
        $season = $stats['season'];
        $total = $stats['total'];

        $line = fn (string $side) => implode("\n", array_map(
            fn (string $kind) => '   '.Feedback::SHORT_KINDS[$kind].": <b>{$season[$side][$kind]}</b> · {$total[$side][$kind]}",
            array_keys(Feedback::KINDS),
        ));

        $growth = $this->stats->growth($stats['weekly']);
        $recent = array_sum(array_slice($stats['weekly'], -4));

        return "👤 <b>{$member->label()}</b>\n"
            .Season::label(Season::number())."\n"
            ."اعداد: <b>این فصل</b> · کل دوره\n\n"
            ."⭐ امتیاز: <b>{$season['points']}</b> · {$total['points']}\n\n"
            ."📥 <b>گرفته</b>\n".$line('received')."\n"
            ."📤 <b>داده</b>\n".$line('given')."\n\n"
            ."👥 از <b>{$total['from_people']}</b> نفر مختلف فیدبک گرفته · به <b>{$total['to_people']}</b> نفر داده\n\n"
            ."📈 روند فعالیت (۱۲ هفته، از چپ قدیمی‌ترین تا راست این هفته)\n"
            .Ui::sparkline($stats['weekly'])."\n"
            ."۴ هفته‌ی اخیر: {$recent} فعالیت".($growth === null ? '' : ' ('.($growth >= 0 ? '↑' : '↓').' '.abs($growth).'٪ نسبت به ۴ هفته‌ی قبل)');
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>
     */
    private function profileButtons(Actor $actor, Member $member): array
    {
        $buttons = [[
            ['text' => '📥 فیدبک‌های گرفته', 'callback_data' => "fl:{$member->id}:r:0"],
            ['text' => '📤 فیدبک‌های داده', 'callback_data' => "fl:{$member->id}:g:0"],
        ]];

        if ($actor->isAdmin) {
            $buttons[] = [['text' => '📄 گزارش کامل (داشبورد + فایل متنی)', 'callback_data' => "pr:{$member->id}"]];
            $buttons[] = [['text' => '↩️ لوپرها', 'callback_data' => 'lp:0']];
        }

        return $buttons;
    }

    /**
     * @param  'r'|'g'  $direction  Received or given.
     */
    private function showList(Actor $actor, Member $subject, string $direction, int $page, int $messageId): void
    {
        $query = Feedback::with(['from', 'to'])->where($direction === 'r' ? 'to_member_id' : 'from_member_id', $subject->id)->latest('id');
        $count = (clone $query)->count();
        $pages = max(1, (int) ceil($count / self::LIST_SIZE));
        $page = max(0, min($page, $pages - 1));
        $items = $query->skip($page * self::LIST_SIZE)->take(self::LIST_SIZE)->get();

        $title = ($direction === 'r' ? '📥 فیدبک‌هایی که ' : '📤 فیدبک‌هایی که ')."<b>{$subject->label()}</b> ".($direction === 'r' ? 'گرفته' : 'داده');
        $lines = $items->values()->map(function (Feedback $feedback, int $index) use ($direction) {
            $other = $direction === 'r' ? 'از '.$feedback->from->label() : 'به '.$feedback->to->label();

            return ($index + 1).') '.Feedback::KINDS[$feedback->kind]." · {$other} · ".Ui::date($feedback->created_at)
                ."\n«".e(mb_strimwidth($feedback->description, 0, 300, '…')).'»';
        });

        $buttons = [];
        if ($actor->isAdmin && $items->isNotEmpty()) {
            $deleteButtons = $items->values()->map(fn (Feedback $feedback, int $index) => [
                'text' => '🗑 '.($index + 1),
                'callback_data' => "del:{$feedback->id}:{$subject->id}:{$direction}:{$page}",
            ]);
            array_push($buttons, ...$deleteButtons->chunk(4)->map(fn ($row) => $row->values()->all())->all());
        }
        $navigation = $this->ui->navigation($page, $pages, fn (int $target) => "fl:{$subject->id}:{$direction}:{$target}");
        if ($navigation) {
            $buttons[] = $navigation;
        }
        $buttons[] = [['text' => '↩️ پروفایل', 'callback_data' => "pf:{$subject->id}"]];

        $this->telegram->edit($actor->chatId, $messageId, $title.($pages > 1 ? ' (صفحه‌ی '.($page + 1)." از {$pages})" : '')."\n\n"
            .($lines->isEmpty() ? 'هنوز فیدبکی نیست.' : $lines->implode("\n\n")), $buttons);
    }

    /**
     * @param  'r'|'g'  $direction
     */
    private function delete(Actor $actor, Member $subject, int $feedbackId, string $direction, int $page, bool $confirmed, int $messageId): void
    {
        $feedback = Feedback::with(['from', 'to'])->find($feedbackId);
        if (! $feedback) {
            $this->showList($actor, $subject, $direction, $page, $messageId);

            return;
        }

        if (! $confirmed) {
            $this->telegram->edit($actor->chatId, $messageId, "این فیدبک حذف بشه؟\n\n<b>".Feedback::KINDS[$feedback->kind]."</b>\n"
                ."از: {$feedback->from->label()}\nبرای: {$feedback->to->label()}\n«".e($feedback->description)."»\n\nامتیازها همون لحظه اصلاح می‌شن.", [[
                    ['text' => '🗑 آره، حذف کن', 'callback_data' => "dly:{$feedback->id}:{$subject->id}:{$direction}:{$page}"],
                    ['text' => '↩️ نه', 'callback_data' => "fl:{$subject->id}:{$direction}:{$page}"],
                ]]);

            return;
        }

        $feedback->update(['deleted_by' => $actor->telegramId]);
        $feedback->delete();
        $this->showList($actor, $subject, $direction, $page, $messageId);
    }
}
