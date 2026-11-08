<?php

namespace App\Services\Bot;

use App\Models\Broadcast;
use App\Models\Member;
use App\Models\SendGroup;
use App\Services\Telegram;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Owner and admins message one looper, a saved group or everyone, signed «پیام تیم لوپ».
 *
 * Steps: audience -> the message itself (text, photo, video, file, voice...) -> preview, exactly as recipients
 * will see it -> send -> report of who got it and who did not. Every send is logged in `broadcasts`.
 */
class BroadcastFlow
{
    public const HEADER = '📢 <b>پیام تیم لوپ</b>';

    /** Media whose caption can carry the header; anything else gets the header as a separate message. */
    private const CAPTIONED = ['photo', 'video', 'document', 'audio', 'voice', 'animation'];

    private const MAX_CAPTION = 1024;

    public function __construct(
        private Telegram $telegram,
        private Ui $ui,
    ) {}

    public static function stateKey(int $telegramId): string
    {
        return "loop-bot:broadcast:{$telegramId}";
    }

    public function start(Actor $actor): void
    {
        Cache::forget(self::stateKey($actor->telegramId));
        [$text, $keyboard] = $this->audienceMenu($actor);
        $this->telegram->send($actor->chatId, $text, $keyboard);
    }

    /**
     * Callbacks: bm, bt:{one|groups|all}, bop:{page}, bo:{member}, bg:{group}, bsend.
     */
    public function handleCallback(Actor $actor, string $action, string $value, int $messageId): void
    {
        if ($action === 'bm') {
            Cache::forget(self::stateKey($actor->telegramId));
            [$text, $keyboard] = $this->audienceMenu($actor);
            $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
        } elseif ($action === 'bt' && $value === 'one') {
            $this->pickOne($actor, 0, $messageId);
        } elseif ($action === 'bop') {
            $this->pickOne($actor, (int) $value, $messageId);
        } elseif ($action === 'bo' && $member = $this->candidates($actor)->firstWhere('id', (int) $value)) {
            $this->askForMessage($actor, ['type' => 'one', 'id' => $member->id], $messageId);
        } elseif ($action === 'bt' && $value === 'groups') {
            $this->pickGroup($actor, $messageId);
        } elseif ($action === 'bg' && $group = SendGroup::find((int) $value)) {
            $this->askForMessage($actor, ['type' => 'group', 'id' => $group->id], $messageId);
        } elseif ($action === 'bt' && $value === 'all') {
            $this->askForMessage($actor, ['type' => 'all'], $messageId);
        } elseif ($action === 'bsend') {
            $this->send($actor, $messageId);
        }
    }

    /**
     * Any message (text or media) while this flow waits for one. Returns false when the flow is not waiting.
     *
     * @param  array<string, mixed>  $message
     */
    public function handleMessage(Actor $actor, array $message): bool
    {
        $state = Cache::get(self::stateKey($actor->telegramId));

        if (($state['step'] ?? null) === 'pick_one' && isset($message['text'])) {
            $this->searchOne($actor, $message['text']);

            return true;
        }

        if (($state['step'] ?? null) !== 'compose') {
            return false;
        }

        $content = $this->content($message);
        if ($content['type'] === 'text' && mb_strlen($content['text']) > 3500) {
            $this->telegram->send($actor->chatId, 'متن خیلی طولانیه (بیشتر از ۳۵۰۰ حرف). کوتاه‌ترش کن و دوباره بفرست:', $this->ui->cancelButton());

            return true;
        }

        Cache::put(self::stateKey($actor->telegramId), [...$state, 'step' => 'confirm', 'content' => $content], now()->addHour());
        $count = $this->recipients($actor, $state['audience'])->count();

        $this->telegram->send($actor->chatId, '👀 <b>پیش‌نمایش</b> — گیرنده‌ها پیام رو دقیقاً این‌طوری می‌بینن:');
        $this->deliver($actor->chatId, $content);
        $this->telegram->send($actor->chatId, "این پیام برای <b>{$this->audienceLabel($state['audience'])}</b> ({$count} نفر) فرستاده بشه؟", [[
            ['text' => "✅ ارسال برای {$count} نفر", 'callback_data' => 'bsend'],
            ['text' => '❌ انصراف', 'callback_data' => 'x'],
        ]]);

        return true;
    }

    /**
     * @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>}
     */
    private function audienceMenu(Actor $actor): array
    {
        $buttons = [
            [['text' => '👤 یک نفر', 'callback_data' => 'bt:one'], ['text' => '👥 یک گروه', 'callback_data' => 'bt:groups']],
            [['text' => '🌍 همه‌ی لوپرها ('.$this->candidates($actor)->count().' نفر)', 'callback_data' => 'bt:all']],
        ];
        if ($actor->isOwner) {
            $buttons[] = [['text' => '⚙️ مدیریت گروه‌های ارسال', 'callback_data' => 'gm']];
        }
        $buttons[] = $this->ui->cancelButton()[0];

        return ["📢 <b>ارسال پیام</b>\nپیام برای چه کسی فرستاده بشه؟\nگیرنده‌ها اون رو با عنوان «پیام تیم لوپ» می‌بینن.", $buttons];
    }

    private function pickOne(Actor $actor, int $page, int $messageId): void
    {
        Cache::put(self::stateKey($actor->telegramId), ['step' => 'pick_one'], now()->addHour());
        [$text, $keyboard] = $this->ui->picker($this->candidates($actor), $page, 'bo', 'bop', "پیام برای کی فرستاده بشه؟\n(یا بخشی از اسمش رو بنویس تا پیداش کنم)");
        $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
    }

    private function searchOne(Actor $actor, string $query): void
    {
        $needle = Ui::normalize($query);
        $matches = $this->candidates($actor)
            ->filter(fn (Member $person) => str_contains(Ui::normalize($person->name.' '.$person->username), $needle))
            ->values();

        if ($matches->isEmpty()) {
            $this->telegram->send($actor->chatId, 'لوپری با این اسم پیدا نشد. یه تکه‌ی دیگه از اسمش رو بنویس.', $this->ui->cancelButton());

            return;
        }

        [$text, $keyboard] = $this->ui->picker($matches->take(Ui::PAGE_SIZE), 0, 'bo', 'bop', 'کدوم یکی؟');
        $this->telegram->send($actor->chatId, $text, $keyboard);
    }

    private function pickGroup(Actor $actor, int $messageId): void
    {
        $groups = SendGroup::withCount(['members' => fn ($query) => $query->where('active', true)])->orderBy('name')->get();

        if ($groups->isEmpty()) {
            $this->telegram->edit($actor->chatId, $messageId, 'هنوز گروه ارسالی ساخته نشده. گروه‌ها رو اونر می‌سازه.', [array_values(array_filter([
                $actor->isOwner ? ['text' => '➕ ساخت گروه', 'callback_data' => 'gn'] : null,
                ['text' => '↩️ برگشت', 'callback_data' => 'bm'],
            ]))]);

            return;
        }

        $buttons = $groups->map(fn (SendGroup $group) => [['text' => "👥 {$group->name} ({$group->members_count} نفر)", 'callback_data' => "bg:{$group->id}"]])->values()->all();
        $buttons[] = [['text' => '↩️ برگشت', 'callback_data' => 'bm']];
        $this->telegram->edit($actor->chatId, $messageId, 'پیام برای کدوم گروه فرستاده بشه؟', $buttons);
    }

    /**
     * @param  array{type: string, id?: int}  $audience
     */
    private function askForMessage(Actor $actor, array $audience, int $messageId): void
    {
        Cache::put(self::stateKey($actor->telegramId), ['step' => 'compose', 'audience' => $audience], now()->addHour());

        $this->telegram->edit($actor->chatId, $messageId, "گیرنده: <b>{$this->audienceLabel($audience)}</b>\n\n"
            ."حالا پیامت رو بفرست 👇\nمتن، عکس، ویدیو، فایل یا ویس — هر چی بفرستی همون برای گیرنده‌ها می‌ره (قبلش پیش‌نمایش می‌بینی).", $this->ui->cancelButton());
    }

    private function send(Actor $actor, int $messageId): void
    {
        $state = Cache::pull(self::stateKey($actor->telegramId));
        if (($state['step'] ?? null) !== 'confirm') {
            $this->telegram->edit($actor->chatId, $messageId, '⌛ این ارسال دیگه معتبر نیست. از «'.Ui::BUTTONS['broadcast'].'» دوباره شروع کن.');

            return;
        }

        $this->telegram->edit($actor->chatId, $messageId, '⏳ در حال ارسال…');
        $recipients = $this->recipients($actor, $state['audience']);
        $delivered = [];
        $failed = [];

        foreach ($recipients as $member) {
            if ($member->telegram_id && $this->deliver($member->telegram_id, $state['content'])) {
                $delivered[] = $member;
            } else {
                $failed[] = $member;
            }
            usleep((int) config('loop.broadcast_delay_ms') * 1000);
        }

        Broadcast::create([
            'sender_telegram_id' => $actor->telegramId,
            'audience' => $this->audienceLabel($state['audience']),
            'kind' => $state['content']['type'],
            'text' => $state['content']['text'] ?? $state['content']['caption'],
            'recipients' => $recipients->count(),
            'delivered' => count($delivered),
        ]);

        $names = fn (array $members) => implode('، ', array_map(fn (Member $member) => $member->label(), $members));
        $notStarted = array_values(array_filter($failed, fn (Member $member) => ! $member->telegram_id));
        $blocked = array_values(array_filter($failed, fn (Member $member) => (bool) $member->telegram_id));

        $this->telegram->edit($actor->chatId, $messageId, '✅ پیام به <b>'.count($delivered).'</b> نفر از '.$recipients->count().' نفر رسید.'
            .($notStarted ? "\n\n⏳ هنوز ربات رو استارت نکردن و پیام بهشون نرسید: ".$names($notStarted) : '')
            .($blocked ? "\n\n⚠️ پیام نرسید (احتمالاً ربات رو بلاک کردن): ".$names($blocked) : ''));
    }

    /**
     * Header + the message, in the recipient's chat.
     *
     * @param  array{type: string, from_chat_id: int, message_id: int, text: ?string, caption: ?string}  $content
     */
    private function deliver(int $chatId, array $content): bool
    {
        if ($content['type'] === 'text') {
            return $this->telegram->send($chatId, self::HEADER."\n\n".e($content['text']));
        }

        if (in_array($content['type'], self::CAPTIONED, true)) {
            $caption = self::HEADER.($content['caption'] ? "\n\n".e($content['caption']) : '');

            return $this->telegram->copyMessage($chatId, $content['from_chat_id'], $content['message_id'], mb_substr($caption, 0, self::MAX_CAPTION));
        }

        return $this->telegram->send($chatId, self::HEADER)
            && $this->telegram->copyMessage($chatId, $content['from_chat_id'], $content['message_id']);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{type: string, from_chat_id: int, message_id: int, text: ?string, caption: ?string}
     */
    private function content(array $message): array
    {
        $types = ['photo', 'video', 'document', 'audio', 'voice', 'animation', 'sticker', 'video_note', 'location', 'contact', 'poll'];

        return [
            'type' => isset($message['text']) ? 'text' : (collect($types)->first(fn (string $type) => isset($message[$type])) ?? 'other'),
            'from_chat_id' => (int) $message['chat']['id'],
            'message_id' => (int) ($message['message_id'] ?? 0),
            'text' => $message['text'] ?? null,
            'caption' => $message['caption'] ?? null,
        ];
    }

    /**
     * Active loopers the actor can message: everyone but themselves.
     *
     * @return Collection<int, Member>
     */
    private function candidates(Actor $actor): Collection
    {
        return $this->ui->activeMembersExcept()->reject(fn (Member $member) => $member->telegram_id === $actor->telegramId)->values();
    }

    /**
     * @param  array{type: string, id?: int}  $audience
     * @return Collection<int, Member>
     */
    private function recipients(Actor $actor, array $audience): Collection
    {
        $candidates = $this->candidates($actor);

        return match ($audience['type']) {
            'one' => $candidates->where('id', $audience['id'])->values(),
            'group' => $candidates->whereIn('id', SendGroup::find($audience['id'])?->members()->pluck('members.id')->all() ?? [])->values(),
            default => $candidates,
        };
    }

    /**
     * @param  array{type: string, id?: int}  $audience
     */
    private function audienceLabel(array $audience): string
    {
        return match ($audience['type']) {
            'one' => Member::find($audience['id'])?->label() ?? 'یک لوپر',
            'group' => 'گروه «'.e(SendGroup::find($audience['id'])?->name ?? '—').'»',
            default => 'همه‌ی لوپرها',
        };
    }
}
