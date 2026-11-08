<?php

namespace App\Services\Bot;

use App\Models\Feedback;
use App\Models\Member;
use App\Models\ScoreRule;
use App\Services\Season;
use App\Services\Telegram;

/**
 * Recording a feedback: pick looper (or type part of a name) -> pick indicator -> describe -> preview -> saved.
 * There is no admin approval: the rated looper gets it at once; negative feedback also alerts the owner and admins.
 */
class FeedbackFlow
{
    private const PICK_TITLE = "با کدوم لوپر همکاری داشتی؟ اسمش رو انتخاب کن\n(یا بخشی از اسمش رو بنویس تا پیداش کنم):";

    private const EXPIRED = '⌛ این مرحله منقضی شده. از منوی پایین دوباره شروع کن.';

    public function __construct(
        private Telegram $telegram,
        private Ui $ui,
        private Access $access,
    ) {}

    public function begin(Actor $actor, Member $member): void
    {
        $targets = $this->ui->activeMembersExcept($member);
        if ($targets->isEmpty()) {
            $this->telegram->send($actor->chatId, 'هنوز لوپر دیگه‌ای توی لیست نیست. ادمین باید لوپرها رو اضافه کنه.');

            return;
        }

        $member->moveTo('choose_target');
        [$text, $keyboard] = $this->ui->picker($targets, 0, 'to', 'p', self::PICK_TITLE);
        $this->telegram->send($actor->chatId, $text, $keyboard);
    }

    /**
     * Free text while a feedback conversation is open. Returns false when no conversation is waiting for text.
     */
    public function handleText(Actor $actor, Member $member, string $text): bool
    {
        if ($member->state === 'choose_target') {
            $this->search($actor, $member, $text);

            return true;
        }

        if ($member->state !== 'await_description') {
            return false;
        }

        if (mb_strlen($text) < 3 || mb_strlen($text) > 1000) {
            $this->telegram->send($actor->chatId, 'توضیح باید بین ۳ تا ۱۰۰۰ حرف باشه. دوباره بنویس:', $this->ui->cancelButton());

            return true;
        }

        $member->moveTo('confirm', [...$member->draft, 'description' => $text]);
        $target = Member::find($member->draft['to']);

        $this->telegram->send($actor->chatId, "👀 <b>پیش‌نمایش</b>\n\n<b>".Feedback::KINDS[$member->draft['kind']]."</b>\n"
            ."برای: {$target?->label()}\n«".e($text)."»\n\nثبت بشه؟ بعد از ثبت، همین الان برای {$target?->label()} فرستاده می‌شه.", [[
                ['text' => '✅ ثبت', 'callback_data' => 'ok'],
                ['text' => '❌ انصراف', 'callback_data' => 'x'],
            ]]);

        return true;
    }

    /**
     * Callbacks: p:{page}, to:{member}, k:{kind}, ok.
     */
    public function handleCallback(Actor $actor, Member $member, string $action, string $value, int $messageId): void
    {
        $expected = ['p' => 'choose_target', 'to' => 'choose_target', 'k' => 'choose_kind', 'ok' => 'confirm'][$action] ?? null;
        if ($expected === null) {
            return;
        }
        if ($member->state !== $expected) {
            $this->telegram->edit($actor->chatId, $messageId, self::EXPIRED);

            return;
        }

        if ($action === 'p') {
            [$text, $keyboard] = $this->ui->picker($this->ui->activeMembersExcept($member), (int) $value, 'to', 'p', self::PICK_TITLE);
            $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
        } elseif ($action === 'to') {
            $this->chooseTarget($actor, $member, (int) $value, $messageId);
        } elseif ($action === 'k' && isset(Feedback::KINDS[$value])) {
            $member->moveTo('await_description', [...$member->draft, 'kind' => $value]);

            $this->telegram->edit($actor->chatId, $messageId, 'انتخاب شد: <b>'.Feedback::KINDS[$value].'</b>');
            $this->telegram->send($actor->chatId, ($value === Feedback::NEGATIVE ? 'مشکل دقیقاً چی بود؟' : 'این ارزش دقیقاً چی بود؟')
                ."\nیه توضیح کوتاه بنویس و بفرست 👇", $this->ui->cancelButton());
        } elseif ($action === 'ok') {
            $this->submit($actor, $member, $messageId);
        }
    }

    private function chooseTarget(Actor $actor, Member $member, int $targetId, int $messageId): void
    {
        $target = $this->ui->activeMembersExcept($member)->firstWhere('id', $targetId);
        if (! $target) {
            return;
        }

        $member->moveTo('choose_kind', ['to' => $target->id]);

        $keyboard = array_map(fn (string $kind) => [['text' => Feedback::KINDS[$kind], 'callback_data' => "k:{$kind}"]], array_keys(Feedback::KINDS));
        $keyboard[] = $this->ui->cancelButton()[0];
        $this->telegram->edit($actor->chatId, $messageId, "از <b>{$target->label()}</b> چی دریافت کردی؟", $keyboard);
    }

    /**
     * Lets the member type part of a name instead of paging through a long list.
     */
    private function search(Actor $actor, Member $member, string $query): void
    {
        $needle = Ui::normalize($query);
        $matches = $this->ui->activeMembersExcept($member)
            ->filter(fn (Member $person) => str_contains(Ui::normalize($person->name.' '.$person->username), $needle))
            ->values();

        if ($matches->isEmpty()) {
            $this->telegram->send($actor->chatId, 'لوپری با این اسم پیدا نشد. یه تکه‌ی دیگه از اسمش رو بنویس یا از لیست انتخاب کن.', $this->ui->cancelButton());

            return;
        }

        [$text, $keyboard] = $this->ui->picker($matches->take(Ui::PAGE_SIZE), 0, 'to', 'p', $matches->count() > Ui::PAGE_SIZE
            ? 'نتیجه‌ها زیاده؛ '.Ui::PAGE_SIZE.' تای اول رو نشون دادم. دقیق‌تر بنویس یا از اینجا انتخاب کن:'
            : 'کدوم یکی؟');
        $this->telegram->send($actor->chatId, $text, $keyboard);
    }

    private function submit(Actor $actor, Member $member, int $messageId): void
    {
        $draft = $member->draft;
        $target = $this->ui->activeMembersExcept($member)->firstWhere('id', $draft['to'] ?? 0);
        if (! $target || ! isset(Feedback::KINDS[$draft['kind'] ?? ''])) {
            $member->resetState();
            $this->telegram->edit($actor->chatId, $messageId, self::EXPIRED);

            return;
        }

        $feedback = Feedback::create([
            'from_member_id' => $member->id,
            'to_member_id' => $target->id,
            'kind' => $draft['kind'],
            'description' => $draft['description'],
            'season' => Season::number(),
        ]);
        $member->resetState();

        $points = ScoreRule::table()[$feedback->kind]['giver'];
        $this->telegram->edit($actor->chatId, $messageId, "✅ فیدبکت برای {$target->label()} ثبت شد".($points !== 0 ? ' · امتیاز تو: '.$this->signed($points) : '').' 🙏');

        $delivered = $this->deliver($feedback);
        if ($delivered === null) {
            $this->telegram->send($actor->chatId, "ℹ️ {$target->label()} هنوز ربات رو استارت نکرده. فیدبکت بعد از اولین استارتش بهش می‌رسه.");
        } elseif ($delivered === false) {
            $this->telegram->send($actor->chatId, "⚠️ پیام به {$target->label()} نرسید (احتمالاً ربات رو بلاک کرده). فیدبک ثبت شده و هر وقت دوباره ربات رو استارت کنه بهش می‌رسه.");
        }

        if ($feedback->isNegative()) {
            $this->alertStaff($feedback);
        }
    }

    /**
     * Tells the owner and the admins about a negative feedback so someone can follow it up.
     */
    private function alertStaff(Feedback $feedback): void
    {
        $involved = [$feedback->from->telegram_id, $feedback->to->telegram_id];

        foreach ($this->access->staffChatIds() as $chatId) {
            if (in_array($chatId, $involved, true)) {
                continue;
            }

            $this->telegram->send($chatId, "⚠️ <b>فیدبک منفی ثبت شد</b>\nاز: {$feedback->from->label()}\nبرای: {$feedback->to->label()}\n«"
                .e($feedback->description).'»', [[['text' => '👤 پروفایل '.($feedback->to->name ?: $feedback->to->username), 'callback_data' => "pf:{$feedback->to->id}"]]]);
        }
    }

    /**
     * Sends a feedback to the rated looper. It stays undelivered, and is retried on their next /start,
     * when they never started the bot (null) or Telegram refused the message, e.g. they blocked it (false).
     */
    public function deliver(Feedback $feedback): ?bool
    {
        $to = $feedback->to;
        if (! $to->telegram_id) {
            return null;
        }

        $points = ScoreRule::table()[$feedback->kind]['receiver'];
        $pointsLine = $points !== 0 ? "\n\nامتیاز تو: ".$this->signed($points) : '';

        $text = $feedback->isNegative()
            ? "⚠️ {$feedback->from->label()} یک فیدبک منفی برات ثبت کرد:\n«".e($feedback->description)."»\n\nلطفاً با ایشان صحبت کن و موضوع رو حل‌وفصل کنید.".$pointsLine
            : "🌟 {$feedback->from->label()} ثبت کرد که ازت <b>".Feedback::KINDS[$feedback->kind]."</b> دریافت کرده:\n«".e($feedback->description).'»'.($pointsLine ?: "\n\n💙");

        if (! $this->telegram->send($to->telegram_id, $text)) {
            return false;
        }

        $feedback->update(['delivered_at' => now()]);

        return true;
    }

    public function deliverWaiting(Member $member): void
    {
        Feedback::with(['from', 'to'])->where('to_member_id', $member->id)->whereNull('delivered_at')->oldest()->get()
            ->each(fn (Feedback $feedback) => $this->deliver($feedback));
    }

    private function signed(int $points): string
    {
        return "\u{200E}".($points > 0 ? '+' : '').$points."\u{200E}";
    }
}
