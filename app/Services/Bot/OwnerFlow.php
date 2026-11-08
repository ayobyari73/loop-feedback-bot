<?php

namespace App\Services\Bot;

use App\Models\Feedback;
use App\Models\Member;
use App\Models\ScoreRule;
use App\Services\Telegram;
use Illuminate\Support\Facades\Cache;

/**
 * Owner-only: appointing admins among the loopers and setting the scoring rules.
 */
class OwnerFlow
{
    public function __construct(
        private Telegram $telegram,
        private Ui $ui,
    ) {}

    public static function scoringKey(int $telegramId): string
    {
        return "loop-bot:score:{$telegramId}";
    }

    public function handleAction(Actor $actor, string $action): void
    {
        if ($action === 'admins') {
            [$text, $keyboard] = $this->adminsView();
            $this->telegram->send($actor->chatId, $text, $keyboard);
        } elseif ($action === 'scoring') {
            [$text, $keyboard] = $this->scoringView();
            $this->telegram->send($actor->chatId, $text, $keyboard);
        }
    }

    /**
     * Callbacks: adm, ada:{page}, adp:{member}, adr:{member}, ady:{member}, sc, se:{kind}:{g|r}.
     */
    public function handleCallback(Actor $actor, string $action, string $value, int $messageId): void
    {
        if ($action === 'adm') {
            [$text, $keyboard] = $this->adminsView();
            $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
        } elseif ($action === 'ada') {
            $candidates = $this->ui->activeMembersExcept()->where('is_admin', false)->values();
            [$text, $keyboard] = $this->ui->picker($candidates, (int) $value, 'adp', 'ada', 'کدوم لوپر ادمین بشه؟');
            $this->telegram->edit($actor->chatId, $messageId, $candidates->isEmpty() ? 'همه‌ی لوپرها ادمین هستن یا لوپری نیست.' : $text, $candidates->isEmpty() ? null : $keyboard);
        } elseif ($action === 'adp' && $member = Member::where('active', true)->find((int) $value)) {
            $member->update(['is_admin' => true]);
            $this->notifyRoleChange($member, true);
            [$text, $keyboard] = $this->adminsView("✅ {$member->label()} ادمین شد.\n\n");
            $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
        } elseif ($action === 'adr' && $member = Member::find((int) $value)) {
            $this->telegram->edit($actor->chatId, $messageId, "دسترسی ادمین «{$member->label()}» برداشته بشه؟\n(خودش لوپر باقی می‌مونه.)", [[
                ['text' => '✅ آره', 'callback_data' => "ady:{$member->id}"],
                ['text' => '↩️ نه', 'callback_data' => 'adm'],
            ]]);
        } elseif ($action === 'ady' && $member = Member::find((int) $value)) {
            $member->update(['is_admin' => false]);
            $this->notifyRoleChange($member, false);
            [$text, $keyboard] = $this->adminsView("✅ {$member->label()} دیگه ادمین نیست.\n\n");
            $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
        } elseif ($action === 'sc') {
            [$text, $keyboard] = $this->scoringView();
            $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
        } elseif ($action === 'se') {
            $this->promptScore($actor, $value);
        }
    }

    /**
     * The owner typed a number after tapping a cell of the scoring table.
     */
    public function receiveScore(Actor $actor, string $cell, string $text): void
    {
        [$kind, $side] = explode(':', $cell) + [1 => 'g'];
        $points = Ui::parseInteger($text);

        if ($points === null || $points < ScoreRule::MIN_POINTS || $points > ScoreRule::MAX_POINTS) {
            Cache::put(self::scoringKey($actor->telegramId), $cell, now()->addHour());
            $this->telegram->send($actor->chatId, 'یه عدد صحیح بین '.ScoreRule::MIN_POINTS.' و '.ScoreRule::MAX_POINTS.' بفرست (مثلاً 2 یا -1):', $this->ui->cancelButton());

            return;
        }

        ScoreRule::updateOrCreate(['kind' => $kind], [$side === 'r' ? 'receiver_points' : 'giver_points' => $points]);

        [$table, $keyboard] = $this->scoringView('✅ ذخیره شد و روی همه‌ی فیدبک‌ها اعمال شد.'."\n\n");
        $this->telegram->send($actor->chatId, $table, $keyboard);
    }

    private function promptScore(Actor $actor, string $cell): void
    {
        [$kind, $side] = explode(':', $cell) + [1 => ''];
        if (! isset(Feedback::KINDS[$kind]) || ! in_array($side, ['g', 'r'], true)) {
            return;
        }

        Cache::put(self::scoringKey($actor->telegramId), "{$kind}:{$side}", now()->addHour());
        $current = ScoreRule::table()[$kind][$side === 'r' ? 'receiver' : 'giver'];

        $this->telegram->send($actor->chatId, 'امتیاز جدید <b>'.($side === 'r' ? 'گیرنده' : 'دهنده').'</b> برای «'.Feedback::KINDS[$kind]."» رو بفرست.\n"
            ."الان: {$current} · بین ".ScoreRule::MIN_POINTS.' و '.ScoreRule::MAX_POINTS.' (منفی هم می‌شه، مثلاً -2)', $this->ui->cancelButton());
    }

    /**
     * @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>}
     */
    private function adminsView(string $prefix = ''): array
    {
        $admins = Member::where('active', true)->where('is_admin', true)->orderBy('name')->get();

        $text = $prefix."👮 <b>ادمین‌ها</b>\n"
            .'ادمین‌ها لوپرها رو مدیریت می‌کنن، پروفایل و فیدبک‌های همه رو می‌بینن، فیدبک حذف می‌کنن و گزارش فصل رو می‌بینن.'."\n\n"
            .($admins->isEmpty() ? 'هنوز ادمینی انتخاب نشده.' : $admins->map(fn (Member $admin) => '• '.$admin->listing())->implode("\n"));

        $buttons = $admins->map(fn (Member $admin) => [['text' => '🗑 برداشتن '.($admin->name ?: $admin->username), 'callback_data' => "adr:{$admin->id}"]])->values()->all();
        $buttons[] = [['text' => '➕ ادمین جدید', 'callback_data' => 'ada:0']];

        return [$text, $buttons];
    }

    /**
     * @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>}
     */
    private function scoringView(string $prefix = ''): array
    {
        $rules = ScoreRule::table();

        $lines = array_map(
            fn (string $kind) => Feedback::SHORT_KINDS[$kind]." — دهنده: <b>{$rules[$kind]['giver']}</b> · گیرنده: <b>{$rules[$kind]['receiver']}</b>",
            array_keys(Feedback::KINDS),
        );
        $buttons = array_map(fn (string $kind) => [
            ['text' => Feedback::SHORT_KINDS[$kind].' دهنده: '.$rules[$kind]['giver'], 'callback_data' => "se:{$kind}:g"],
            ['text' => Feedback::SHORT_KINDS[$kind].' گیرنده: '.$rules[$kind]['receiver'], 'callback_data' => "se:{$kind}:r"],
        ], array_keys(Feedback::KINDS));

        return [$prefix."⚙️ <b>سیستم امتیازدهی</b>\nبرای هر فیدبک، ثبت‌کننده (دهنده) و کسی که فیدبک براش ثبت شده (گیرنده) این امتیازها رو می‌گیرن:\n\n"
            .implode("\n", $lines)
            ."\n\nبرای تغییر، روی هر خانه بزن. تغییرها روی همه‌ی فیدبک‌های قبلی هم اعمال می‌شن.", $buttons];
    }

    private function notifyRoleChange(Member $member, bool $isAdmin): void
    {
        if (! $member->telegram_id) {
            return;
        }

        $actor = new Actor($member->telegram_id, $member->telegram_id, ['id' => $member->telegram_id], $member, false, $isAdmin);
        $this->telegram->send($member->telegram_id, $isAdmin
            ? '👮 اونر تو رو ادمین کرد. حالا می‌تونی لوپرها رو مدیریت کنی، پروفایل همه رو ببینی و گزارش فصل رو بگیری. دکمه‌های جدید پایین صفحه‌ست 👇'
            : 'دسترسی ادمین تو برداشته شد. همچنان لوپری و می‌تونی فیدبک ثبت کنی 👇', null, $this->ui->menu($actor));
    }
}
