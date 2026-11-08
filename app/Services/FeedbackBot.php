<?php

namespace App\Services;

use App\Models\Member;
use App\Services\Bot\Access;
use App\Services\Bot\Actor;
use App\Services\Bot\AdminFlow;
use App\Services\Bot\BroadcastFlow;
use App\Services\Bot\FeedbackFlow;
use App\Services\Bot\OwnerFlow;
use App\Services\Bot\ProfileFlow;
use App\Services\Bot\SendGroupFlow;
use App\Services\Bot\Ui;
use Illuminate\Support\Facades\Cache;

/**
 * Entry point of the Loop peer-feedback bot: works out who sent an update and hands it to the right flow.
 *
 * Roles: the owner (from .env) appoints admins among the loopers, sets the scoring and manages send groups;
 * admins manage loopers, see every profile and the reports, and message loopers; loopers record feedback
 * and see their own profile.
 */
class FeedbackBot
{
    private const ADMIN_ACTIONS = ['addlooper', 'loopers', 'remove', 'reports', 'season', 'month', 'broadcast'];

    private const OWNER_ACTIONS = ['admins', 'scoring'];

    private const ADMIN_CALLBACKS = ['addl', 'lp', 'rml', 'rm', 'rmy', 'rp', 'pr', 'bm', 'bt', 'bop', 'bo', 'bg', 'bsend'];

    private const OWNER_CALLBACKS = ['adm', 'ada', 'adp', 'adr', 'ady', 'sc', 'se', 'gm', 'gn', 'ge', 'gt', 'gx', 'gxy'];

    private const BROADCAST_CALLBACKS = ['bm', 'bt', 'bop', 'bo', 'bg', 'bsend'];

    private const GROUP_CALLBACKS = ['gm', 'gn', 'ge', 'gt', 'gx', 'gxy'];

    private const PROFILE_CALLBACKS = ['pf', 'fl', 'del', 'dly'];

    public function __construct(
        private Telegram $telegram,
        private Access $access,
        private Ui $ui,
        private FeedbackFlow $feedback,
        private ProfileFlow $profile,
        private AdminFlow $admin,
        private OwnerFlow $owner,
        private BroadcastFlow $broadcast,
        private SendGroupFlow $groups,
    ) {}

    /**
     * @param  array<string, mixed>  $update  A raw Telegram update.
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        } elseif (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleMessage(array $message): void
    {
        if (($message['chat']['type'] ?? '') !== 'private' || ! isset($message['from'])) {
            return;
        }

        $chatId = (int) $message['chat']['id'];
        $actor = $this->access->actor($message['from'], $chatId, $this->resolveMember($message['from']));

        if (! isset($message['text'])) {
            if (! ($actor->isAdmin && $this->broadcast->handleMessage($actor, $message))) {
                $this->telegram->send($chatId, 'فعلاً فقط پیام متنی می‌فهمم 🙂 لطفاً به‌جای عکس، ویس یا فایل، با متن بنویس.');
            }

            return;
        }

        $text = trim($message['text']);
        $expired = $actor->member?->conversationExpired() ?? false;
        if ($expired) {
            $actor->member->resetState();
        }

        $action = array_search($text, Ui::BUTTONS, true) ?: null;
        $args = '';
        if (preg_match('#^/(\w+)(?:@\w+)?\s*(.*)$#su', $text, $matches)) {
            $action = strtolower($matches[1]);
            $args = trim($matches[2]);
        }

        if ($action === 'id') {
            $this->telegram->send($chatId, "شناسه‌ی عددی تو:\n<code>{$actor->telegramId}</code>");

            return;
        }

        if ($action !== null) {
            $this->clearConversation($actor);
        }

        if (in_array($action, self::ADMIN_ACTIONS, true) || in_array($action, self::OWNER_ACTIONS, true)) {
            $allowed = in_array($action, self::OWNER_ACTIONS, true) ? $actor->isOwner : $actor->isAdmin;

            if (! $allowed) {
                $this->telegram->send($chatId, 'این بخش برای تو باز نیست.', null, $this->ui->menu($actor));
            } elseif (in_array($action, self::OWNER_ACTIONS, true)) {
                $this->owner->handleAction($actor, $action);
            } elseif ($action === 'broadcast') {
                $this->broadcast->start($actor);
            } else {
                $this->admin->handleAction($actor, $action, $args);
            }

            return;
        }

        if ($action === 'start' && ($actor->member || $actor->isAdmin)) {
            $this->start($actor);

            return;
        }

        if ($action === 'cancel') {
            $this->telegram->send($chatId, 'لغو شد. هر وقت خواستی از منوی پایین ادامه بده 👇', null, $this->ui->menu($actor));

            return;
        }

        if ($action === null && $actor->isOwner && $cell = Cache::pull(OwnerFlow::scoringKey($actor->telegramId))) {
            $this->owner->receiveScore($actor, $cell, $text);

            return;
        }

        if ($action === null && $actor->isOwner && $this->groups->handleText($actor, $text)) {
            return;
        }

        if ($action === null && $actor->isAdmin && $this->broadcast->handleMessage($actor, $message)) {
            return;
        }

        if ($action === null && $actor->isAdmin && Cache::pull(AdminFlow::addingKey($actor->telegramId))) {
            $this->admin->addLoopers($actor, $text);

            return;
        }

        if (! $actor->member) {
            $this->notInList($actor);

            return;
        }

        if ($action === 'feedback') {
            $this->feedback->begin($actor, $actor->member);
        } elseif ($action === 'me') {
            $this->profile->showOwn($actor);
        } elseif ($action !== null || ! $this->feedback->handleText($actor, $actor->member, $text)) {
            $this->telegram->send($chatId, ($expired ? "⌛ گفتگوی ثبت فیدبک قبلی منقضی شده بود و ثبت نشد.\n" : '')
                .'از دکمه‌های منوی پایین استفاده کن 👇', null, $this->ui->menu($actor));
        }
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function handleCallback(array $callback): void
    {
        $chatId = (int) $callback['message']['chat']['id'];
        $messageId = (int) $callback['message']['message_id'];
        [$action, $value] = array_pad(explode(':', $callback['data'] ?? '', 2), 2, '');
        $actor = $this->access->actor($callback['from'], $chatId, $this->resolveMember($callback['from']));

        $allowed = match (true) {
            in_array($action, self::OWNER_CALLBACKS, true) => $actor->isOwner,
            in_array($action, self::ADMIN_CALLBACKS, true) => $actor->isAdmin,
            default => true,
        };
        if (! $allowed) {
            $this->telegram->answerCallback($callback['id'], 'این کار فقط از دست '.(in_array($action, self::OWNER_CALLBACKS, true) ? 'اونر' : 'ادمین').' برمیاد.');

            return;
        }

        $this->telegram->answerCallback($callback['id']);

        if ($action === 'x') {
            $this->clearConversation($actor);
            $this->telegram->edit($chatId, $messageId, 'لغو شد.');
        } elseif ($action === 'selfadd' && $actor->isAdmin) {
            $this->addSelf($actor, $messageId);
        } elseif (in_array($action, self::GROUP_CALLBACKS, true)) {
            $this->groups->handleCallback($actor, $action, $value, $messageId);
        } elseif (in_array($action, self::BROADCAST_CALLBACKS, true)) {
            $this->broadcast->handleCallback($actor, $action, $value, $messageId);
        } elseif (in_array($action, self::OWNER_CALLBACKS, true)) {
            $this->owner->handleCallback($actor, $action, $value, $messageId);
        } elseif (in_array($action, self::ADMIN_CALLBACKS, true)) {
            $this->admin->handleCallback($actor, $action, $value, $messageId);
        } elseif (in_array($action, self::PROFILE_CALLBACKS, true)) {
            $this->profile->handleCallback($actor, $action, $value, $messageId);
        } elseif ($actor->member) {
            if ($actor->member->conversationExpired()) {
                $actor->member->resetState();
            }
            $this->feedback->handleCallback($actor, $actor->member, $action, $value, $messageId);
        }
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function resolveMember(array $from): ?Member
    {
        $member = Member::where('telegram_id', $from['id'])->first();

        if (! $member && isset($from['username'])) {
            $member = Member::whereNull('telegram_id')->whereRaw('lower(username) = ?', [strtolower($from['username'])])->first();
        }

        if (! $member || ! $member->active) {
            return null;
        }

        $member->telegram_id = $from['id'];
        $member->name ??= trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));
        $this->syncUsername($member, $from['username'] ?? null);
        $member->save();

        return $member;
    }

    /**
     * Follows a looper who renamed their Telegram username, unless the new one already belongs to someone else.
     */
    private function syncUsername(Member $member, ?string $username): void
    {
        if (! $username || strcasecmp($member->username, $username) === 0) {
            return;
        }

        $taken = Member::whereRaw('lower(username) = ?', [strtolower($username)])->where('id', '!=', $member->id)->exists();
        if (! $taken) {
            $member->username = $username;
        }
    }

    private function clearConversation(Actor $actor): void
    {
        $actor->member?->resetState();
        Cache::forget(AdminFlow::addingKey($actor->telegramId));
        Cache::forget(OwnerFlow::scoringKey($actor->telegramId));
        Cache::forget(BroadcastFlow::stateKey($actor->telegramId));
        Cache::forget(SendGroupFlow::stateKey($actor->telegramId));
    }

    private function notInList(Actor $actor): void
    {
        if ($actor->isAdmin) {
            $this->telegram->send($actor->chatId, "برای ثبت فیدبک و داشتن پروفایل باید اسم خودت هم توی لیست لوپرها باشه.\nبزنم اضافه‌ات کنم؟", [[
                ['text' => '✅ آره، منو اضافه کن', 'callback_data' => 'selfadd'],
            ]]);

            return;
        }

        $username = $actor->from['username'] ?? null;
        $this->telegram->send($actor->chatId, "اسم تو هنوز توی لیست لوپرها نیست 🙁\nاز ادمین بخواه یوزرنیم تلگرامت رو اضافه کنه.\n\n"
            .($username
                ? "یوزرنیم تلگرام تو:\n<code>@{$username}</code>"
                : 'ضمناً تو تلگرام یوزرنیم نداری. از تنظیمات تلگرام یکی بساز و دوباره /start بزن.'));
    }

    private function start(Actor $actor): void
    {
        $roles = $actor->isOwner ? 'تو اونر هستی: ادمین‌ها و سیستم امتیازدهی دست توست.' : ($actor->isAdmin ? 'تو ادمین هستی: لوپرها، پروفایل‌ها و گزارش فصل دست توست.' : '');

        if (! $actor->member) {
            $this->telegram->send($actor->chatId, "سلام 👋\n{$roles}\n\nبرای شروع، لوپرها رو با دکمه‌ی «".Ui::BUTTONS['addlooper'].'» اضافه کن. اگه خودت هم لوپری، با «'.Ui::BUTTONS['feedback'].'» اسم خودت رو هم به لیست اضافه کن.', null, $this->ui->menu($actor));

            return;
        }

        $this->telegram->send($actor->chatId, "سلام <b>{$actor->member->label()}</b> 👋\nبه ربات فیدبک «لوپ» خوش اومدی.\n\n"
            .'هر وقت یه لوپر بهت شبکه، دانش یا تجربه منتقل کرد، با دکمه‌ی «'.Ui::BUTTONS['feedback']."» ثبتش کن؛ همون لحظه بهش می‌رسه و هر دوتون امتیاز می‌گیرید.\n"
            .'پروفایلت رو با «'.Ui::BUTTONS['me']."» ببین. فیدبک‌ها ناشناس نیستن و ادمین‌ها هم می‌بیننشون.\n"
            .($roles ? "\n{$roles}\n" : '')
            ."\nهمه‌ی کارها با دکمه‌های پایین صفحه انجام می‌شه 👇", null, $this->ui->menu($actor));

        $this->feedback->deliverWaiting($actor->member);
    }

    private function addSelf(Actor $actor, int $messageId): void
    {
        $username = $actor->from['username'] ?? null;
        if (! $username) {
            $this->telegram->edit($actor->chatId, $messageId, 'تو تلگرام یوزرنیم نداری. از تنظیمات تلگرام یکی بساز و دوباره امتحان کن.');

            return;
        }

        $member = Member::whereRaw('lower(username) = ?', [strtolower($username)])->first() ?? new Member(['username' => $username]);
        $member->fill([
            'active' => true,
            'telegram_id' => $actor->telegramId,
            'name' => $member->name ?: trim(($actor->from['first_name'] ?? '').' '.($actor->from['last_name'] ?? '')),
        ])->save();

        $this->telegram->edit($actor->chatId, $messageId, '✅ اسمت به لیست لوپرها اضافه شد.');
        $this->telegram->send($actor->chatId, 'حالا می‌تونی از منوی پایین فیدبک ثبت کنی و پروفایلت رو ببینی 👇', null,
            $this->ui->menu($this->access->actor($actor->from, $actor->chatId, $member)));
    }
}
