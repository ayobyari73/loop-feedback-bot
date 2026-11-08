<?php

namespace App\Services\Bot;

use App\Models\Member;
use App\Models\SendGroup;
use App\Services\Telegram;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Owner-only: saved send groups («تیم فروش», «منتورها», ...) that admins can message in one go.
 * Members are picked by tapping names (✅ in the group, ▫️ not), or by typing part of a name.
 */
class SendGroupFlow
{
    public function __construct(
        private Telegram $telegram,
        private Ui $ui,
    ) {}

    public static function stateKey(int $telegramId): string
    {
        return "loop-bot:group:{$telegramId}";
    }

    /**
     * Callbacks: gm, gn, ge:{group}:{page}, gt:{group}:{page}:{member}, gx:{group}, gxy:{group}.
     */
    public function handleCallback(Actor $actor, string $action, string $value, int $messageId): void
    {
        $parts = array_map('intval', explode(':', $value));

        if ($action === 'gm') {
            Cache::forget(self::stateKey($actor->telegramId));
            [$text, $keyboard] = $this->listView();
            $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
        } elseif ($action === 'gn') {
            Cache::put(self::stateKey($actor->telegramId), ['step' => 'name'], now()->addHour());
            $this->telegram->send($actor->chatId, 'اسم گروه جدید رو بفرست (مثلاً «تیم فروش» یا «منتورها»):', $this->ui->cancelButton());
        } elseif (in_array($action, ['ge', 'gt', 'gx', 'gxy'], true) && $group = SendGroup::find($parts[0] ?? 0)) {
            match ($action) {
                'ge' => $this->editMembers($actor, $group, $parts[1] ?? 0, $messageId),
                'gt' => $this->toggle($actor, $group, $parts[2] ?? 0, $parts[1] ?? 0, $messageId),
                'gx' => $this->telegram->edit($actor->chatId, $messageId, 'گروه «'.e($group->name).'» حذف بشه؟ (خود لوپرها حذف نمی‌شن.)', [[
                    ['text' => '🗑 آره، حذف کن', 'callback_data' => "gxy:{$group->id}"],
                    ['text' => '↩️ نه', 'callback_data' => "ge:{$group->id}:0"],
                ]]),
                'gxy' => $this->delete($actor, $group, $messageId),
            };
        }
    }

    /**
     * Text while creating a group (its name) or editing members (a search). Returns false if not waiting for text.
     */
    public function handleText(Actor $actor, string $text): bool
    {
        $state = Cache::get(self::stateKey($actor->telegramId));
        if (! $state) {
            return false;
        }

        if ($state['step'] === 'name') {
            $this->create($actor, $text);
        } elseif ($state['step'] === 'members' && $group = SendGroup::find($state['group'])) {
            $this->search($actor, $group, $text);
        }

        return true;
    }

    /**
     * @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>}
     */
    private function listView(string $prefix = ''): array
    {
        $groups = SendGroup::withCount(['members' => fn ($query) => $query->where('active', true)])->orderBy('name')->get();

        $text = $prefix."👥 <b>گروه‌های ارسال</b>\nادمین‌ها و تو می‌تونید با یه لمس به همه‌ی اعضای یه گروه پیام بدید.\n\n"
            .($groups->isEmpty() ? 'هنوز گروهی نساختی.' : $groups->map(fn (SendGroup $group) => '• '.e($group->name)." ({$group->members_count} نفر)")->implode("\n"));

        $buttons = $groups->map(fn (SendGroup $group) => [['text' => "✏️ {$group->name} ({$group->members_count})", 'callback_data' => "ge:{$group->id}:0"]])->values()->all();
        $buttons[] = [['text' => '➕ گروه جدید', 'callback_data' => 'gn'], ['text' => '↩️ ارسال پیام', 'callback_data' => 'bm']];

        return [$text, $buttons];
    }

    private function create(Actor $actor, string $name): void
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            $this->telegram->send($actor->chatId, 'اسم گروه باید بین ۲ تا ۴۰ حرف باشه. دوباره بفرست:', $this->ui->cancelButton());

            return;
        }
        if (SendGroup::where('name', $name)->exists()) {
            $this->telegram->send($actor->chatId, 'گروهی با این اسم هست. یه اسم دیگه بفرست:', $this->ui->cancelButton());

            return;
        }

        $group = SendGroup::create(['name' => $name]);
        Cache::put(self::stateKey($actor->telegramId), ['step' => 'members', 'group' => $group->id], now()->addHour());
        [$text, $keyboard] = $this->membersView($group, 0, '✅ گروه «'.e($name)."» ساخته شد. حالا اعضاش رو انتخاب کن.\n\n");
        $this->telegram->send($actor->chatId, $text, $keyboard);
    }

    private function editMembers(Actor $actor, SendGroup $group, int $page, int $messageId): void
    {
        Cache::put(self::stateKey($actor->telegramId), ['step' => 'members', 'group' => $group->id], now()->addHour());
        [$text, $keyboard] = $this->membersView($group, $page);
        $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
    }

    private function toggle(Actor $actor, SendGroup $group, int $memberId, int $page, int $messageId): void
    {
        if (Member::where('active', true)->whereKey($memberId)->exists()) {
            $group->members()->toggle([$memberId]);
        }

        $this->editMembers($actor, $group, $page, $messageId);
    }

    private function search(Actor $actor, SendGroup $group, string $query): void
    {
        $needle = Ui::normalize($query);
        $matches = $this->ui->activeMembersExcept()
            ->filter(fn (Member $person) => str_contains(Ui::normalize($person->name.' '.$person->username), $needle))
            ->values();

        if ($matches->isEmpty()) {
            $this->telegram->send($actor->chatId, 'لوپری با این اسم پیدا نشد. یه تکه‌ی دیگه از اسمش رو بنویس.', [[['text' => '↩️ لیست اعضا', 'callback_data' => "ge:{$group->id}:0"]]]);

            return;
        }

        [$text, $keyboard] = $this->membersView($group, 0, '', $matches->take(Ui::PAGE_SIZE));
        $this->telegram->send($actor->chatId, $text, $keyboard);
    }

    /**
     * @param  Collection<int, Member>|null  $people  Defaults to every active looper.
     * @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>}
     */
    private function membersView(SendGroup $group, int $page, string $prefix = '', $people = null): array
    {
        $inGroup = $group->members()->pluck('members.id')->all();
        $people ??= $this->ui->activeMembersExcept();
        $pages = max(1, (int) ceil($people->count() / Ui::PAGE_SIZE));
        $page = max(0, min($page, $pages - 1));

        return $this->ui->picker(
            $people,
            $page,
            "gt:{$group->id}:{$page}",
            "ge:{$group->id}",
            $prefix.'👥 اعضای «<b>'.e($group->name).'</b>» ('.count($inGroup)." نفر)\nروی هر اسم بزن تا به گروه اضافه یا ازش حذف بشه (✅ یعنی عضوه).\nبرای پیدا کردن سریع، بخشی از اسم رو بنویس.",
            fn (Member $person) => (in_array($person->id, $inGroup, true) ? '✅ ' : '▫️ ').($person->name ?: $person->handle()),
            [[['text' => '✔️ تمام', 'callback_data' => 'gm'], ['text' => '🗑 حذف گروه', 'callback_data' => "gx:{$group->id}"]]],
            cancel: false,
        );
    }

    private function delete(Actor $actor, SendGroup $group, int $messageId): void
    {
        $name = $group->name;
        $group->delete();
        Cache::forget(self::stateKey($actor->telegramId));

        [$text, $keyboard] = $this->listView('✅ گروه «'.e($name)."» حذف شد.\n\n");
        $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
    }
}
