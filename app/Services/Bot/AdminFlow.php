<?php

namespace App\Services\Bot;

use App\Models\Feedback;
use App\Models\Member;
use App\Services\ProfileStats;
use App\Services\Reports\Period;
use App\Services\Reports\ReportSender;
use App\Services\Telegram;
use Illuminate\Support\Facades\Cache;

/**
 * What admins (and the owner) can do: manage the looper list, browse everyone's profile and get the
 * monthly, seasonal and personal reports (a summary in chat plus an HTML dashboard and a Markdown export).
 */
class AdminFlow
{
    private const HANDLE_PATTERN = '/(?:t\.me\/|@)([A-Za-z][A-Za-z0-9_]{4,31})\b/u';

    private const USERNAME_PATTERN = '/^@?([A-Za-z][A-Za-z0-9_]{4,31})$/';

    private const SUMMARY_SIZE = 10;

    public function __construct(
        private Telegram $telegram,
        private Ui $ui,
        private ProfileStats $stats,
        private ReportSender $reports,
    ) {}

    public static function addingKey(int $telegramId): string
    {
        return "loop-bot:adding:{$telegramId}";
    }

    public function handleAction(Actor $actor, string $action, string $args): void
    {
        match ($action) {
            'addlooper' => $args === '' ? $this->promptAddLoopers($actor) : $this->addLoopers($actor, $args),
            'loopers' => $this->listLoopers($actor),
            'remove' => $this->removeByCommand($actor, $args),
            'reports' => $this->chooseReport($actor),
            'season' => $this->periodReport($actor, $args === 'prev' ? Period::currentSeason()->previous() : Period::currentSeason()),
            'month' => $this->periodReport($actor, $args === 'prev' ? Period::currentMonth()->previous() : Period::currentMonth()),
        };
    }

    /**
     * Callbacks: addl, lp:{page}, rml:{page}, rm:{member}, rmy:{member}, rp:{m|s}:{number}, pr:{member}.
     */
    public function handleCallback(Actor $actor, string $action, string $value, int $messageId): void
    {
        if ($action === 'addl') {
            $this->promptAddLoopers($actor);
        } elseif ($action === 'lp') {
            [$text, $keyboard] = $this->loopersPicker((int) $value);
            $this->telegram->edit($actor->chatId, $messageId, $text, $keyboard);
        } elseif ($action === 'rml') {
            $people = $this->ui->activeMembersExcept();
            [$text, $keyboard] = $this->ui->picker($people, (int) $value, 'rm', 'rml', 'کدوم لوپر از لیست حذف بشه؟');
            $this->telegram->edit($actor->chatId, $messageId, $people->isEmpty() ? 'لیست خالیه.' : $text, $people->isEmpty() ? null : $keyboard);
        } elseif ($action === 'rm' && $member = Member::find((int) $value)) {
            $this->telegram->edit($actor->chatId, $messageId, "مطمئنی «{$member->label()}» از لیست لوپرها حذف بشه؟\n(امتیازها و سوابقش نگه داشته می‌شه.)", [[
                ['text' => '🗑 آره، حذف کن', 'callback_data' => "rmy:{$member->id}"],
                ['text' => '↩️ نه', 'callback_data' => 'x'],
            ]]);
        } elseif ($action === 'rmy' && $member = Member::find((int) $value)) {
            $member->update(['active' => false, 'is_admin' => false, 'state' => 'idle', 'draft' => null]);
            $this->telegram->edit($actor->chatId, $messageId, "✅ {$member->label()} از لیست حذف شد.");
        } elseif ($action === 'rp' && preg_match('/^([ms]):(-?\d{1,5})$/', $value, $matches)) {
            $period = $matches[1] === 'm' ? Period::month((int) $matches[2]) : Period::season((int) $matches[2]);
            if ($period->start->lte(now())) {
                $this->periodReport($actor, $period);
            }
        } elseif ($action === 'pr' && $member = Member::find((int) $value)) {
            $this->telegram->send($actor->chatId, "⏳ دارم گزارش شخصی {$member->label()} رو آماده می‌کنم…");
            $this->reports->sendMember($actor->chatId, $member);
        }
    }

    private function chooseReport(Actor $actor): void
    {
        $month = Period::currentMonth();
        $season = Period::currentSeason();
        $buttons = [
            [['text' => '📅 '.$month->title, 'callback_data' => $month->callback()], ['text' => '📅 '.$month->previous()->title, 'callback_data' => $month->previous()->callback()]],
            array_values(array_filter([
                ['text' => '🗓 این فصل', 'callback_data' => $season->callback()],
                $season->number > 0 ? ['text' => '🗓 فصل قبل', 'callback_data' => $season->previous()->callback()] : null,
            ])),
        ];

        $this->telegram->send($actor->chatId, "📊 <b>کدوم گزارش؟</b>\nهر گزارش یه خلاصه اینجا، یه داشبورد گرافیکی (فایل HTML) و یه فایل متنی برای تحلیل با هوش مصنوعی داره.\n"
            .'برای گزارش شخصی هر لوپر، از «'.Ui::BUTTONS['loopers'].'» پروفایلش رو باز کن.', $buttons);
    }

    public function promptAddLoopers(Actor $actor): void
    {
        Cache::put(self::addingKey($actor->telegramId), true, now()->addHour());

        $this->telegram->send($actor->chatId, "یوزرنیم تلگرام و اسم لوپر رو بفرست، مثل این:\n\n<code>علی رضایی @ali_rezaei</code>\n\n"
            ."اگه چندتا لوپر داری، هر کدوم رو توی یه خط بفرست (می‌تونی لیست رو یک‌جا کپی کنی).\nلینک t.me هم قبول می‌کنم.", $this->ui->cancelButton());
    }

    public function addLoopers(Actor $actor, string $input): void
    {
        $added = [];
        $invalid = [];

        foreach (preg_split('/\R/u', $input, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $parsed = $this->parseLooperLine(trim($line));

            if (! $parsed) {
                $invalid[] = e(trim($line));

                continue;
            }

            [$username, $name] = $parsed;
            $member = Member::whereRaw('lower(username) = ?', [strtolower($username)])->first() ?? new Member(['username' => $username]);
            $member->active = true;
            $member->name = $name ?: $member->name;
            $member->save();
            $added[] = "• {$member->listing()}";
        }

        if (! $added) {
            Cache::put(self::addingKey($actor->telegramId), true, now()->addHour());
            $this->telegram->send($actor->chatId, "یوزرنیم تلگرام تشخیص داده نشد 🤔\nیوزرنیم باید با @ شروع بشه، مثلاً:\n<code>علی رضایی @ali_rezaei</code>\nدوباره بفرست:", $this->ui->cancelButton());

            return;
        }

        $this->telegram->send($actor->chatId, "✅ به لیست لوپرها اضافه شد:\n".implode("\n", $added)
            .($invalid ? "\n\n❗ این خط‌ها یوزرنیم معتبر نداشتن و اضافه نشدن:\n".implode("\n", $invalid) : ''), [[
                ['text' => '➕ افزودن لوپر دیگر', 'callback_data' => 'addl'],
                ['text' => '👥 لوپرها', 'callback_data' => 'lp:0'],
            ]]);
    }

    /**
     * Understands "Name @handle", "@handle Name", "t.me/handle" and a bare "handle".
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function parseLooperLine(string $line): ?array
    {
        $line = preg_replace('#https?://#i', '', $line);

        if (preg_match(self::HANDLE_PATTERN, $line, $matches)) {
            $handle = $matches[1];
            $rest = str_replace($matches[0], ' ', $line);
        } else {
            $handle = collect(preg_split('/\s+/u', $line))->first(fn (string $token) => (bool) preg_match(self::USERNAME_PATTERN, $token));
            if (! $handle) {
                return null;
            }
            $rest = preg_replace('/\b'.preg_quote($handle, '/').'\b/u', ' ', $line, 1);
            $handle = ltrim($handle, '@');
        }

        $name = preg_replace('/^[\s\-–—:،,|]+|[\s\-–—:،,|]+$/u', '', preg_replace('/\s+/u', ' ', $rest));

        return [$handle, $name !== '' ? $name : null];
    }

    private function listLoopers(Actor $actor): void
    {
        if ($this->ui->activeMembersExcept()->isEmpty()) {
            $this->telegram->send($actor->chatId, 'لیست خالیه. اولین لوپر رو اضافه کن 👇', [[['text' => '➕ افزودن لوپر', 'callback_data' => 'addl']]]);

            return;
        }

        [$text, $keyboard] = $this->loopersPicker(0);
        $this->telegram->send($actor->chatId, $text, $keyboard);
    }

    /**
     * @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>}
     */
    private function loopersPicker(int $page): array
    {
        $people = $this->ui->activeMembersExcept();

        return $this->ui->picker(
            $people,
            $page,
            'pf',
            'lp',
            "<b>لوپرها ({$people->count()})</b>\n✅ ربات رو استارت کرده · ⏳ هنوز نه · 👮 ادمین\nروی اسم هر نفر بزن تا پروفایلش رو ببینی:",
            fn (Member $person) => ($person->telegram_id ? '✅ ' : '⏳ ').($person->is_admin ? '👮 ' : '').($person->name ?: $person->handle()),
            [[['text' => '➕ افزودن', 'callback_data' => 'addl'], ['text' => '🗑 حذف لوپر', 'callback_data' => 'rml:0']]],
            cancel: false,
        );
    }

    private function removeByCommand(Actor $actor, string $args): void
    {
        if (! preg_match(self::USERNAME_PATTERN, $args, $matches)) {
            $this->telegram->send($actor->chatId, 'برای حذف، دکمه‌ی «'.Ui::BUTTONS['loopers'].'» رو بزن و بعد «🗑 حذف لوپر».');

            return;
        }

        $member = Member::whereRaw('lower(username) = ?', [strtolower($matches[1])])->first();
        if (! $member) {
            $this->telegram->send($actor->chatId, 'همچین لوپری توی لیست نیست.');

            return;
        }

        $member->update(['active' => false, 'is_admin' => false, 'state' => 'idle', 'draft' => null]);
        $this->telegram->send($actor->chatId, "✅ {$member->label()} از لیست حذف شد (سوابقش نگه داشته می‌شه).");
    }

    /**
     * A short summary in chat (top loopers), followed by the full HTML dashboard and the Markdown export.
     */
    private function periodReport(Actor $actor, Period $period): void
    {
        $summaries = $this->stats->forRange($period->start, $period->end);
        $emoji = ['network' => '🌐', 'knowledge' => '📚', 'experience' => '🧭', Feedback::NEGATIVE => '⚠️'];
        $counts = fn (array $byKind) => implode(' ', array_map(fn (string $kind) => $emoji[$kind].$byKind[$kind], array_keys(Feedback::KINDS)));

        $rows = Member::where('active', true)->get()
            ->map(fn (Member $member) => [$member, $summaries[$member->id] ?? $this->stats->blank()])
            ->sortByDesc(fn (array $row) => $row[1]['points'])
            ->values();
        $lines = $rows->take(self::SUMMARY_SIZE)
            ->map(fn (array $row) => "• <b>{$row[0]->label()}</b> — ⭐ {$row[1]['points']}\n"
                ."   📥 {$counts($row[1]['received'])} · از {$row[1]['from_people']} نفر\n"
                ."   📤 {$counts($row[1]['given'])} · به {$row[1]['to_people']} نفر");

        $total = ProfileStats::inRange($period->start, $period->end)->count();
        $weekly = $this->stats->communityWeekly();
        $growth = $this->stats->growth($weekly);
        $noun = $period->noun();

        $buttons = [array_values(array_filter([
            $period->type === 'month' || $period->number > 0 ? ['text' => "◀️ {$noun} قبل", 'callback_data' => $period->previous()->callback()] : null,
            $period->isCurrentOrFuture() ? null : ['text' => "{$noun} بعد ▶️", 'callback_data' => $period->next()->callback()],
        ]))];

        $this->telegram->send($actor->chatId, "📊 <b>گزارش {$period->title}</b>\n{$period->range()}\n"
            ."فیدبک‌های این {$noun}: <b>{$total}</b>\n"
            ."📈 روند کل جامعه (۱۲ هفته‌ی اخیر):\n".Ui::sparkline($weekly)
            .($growth === null ? '' : "\n۴ هفته‌ی اخیر نسبت به قبل: ".($growth >= 0 ? '↑' : '↓').' '.abs($growth).'٪')
            ."\n\n⭐ امتیاز · 📥 گرفته · 📤 داده (🌐 شبکه 📚 دانش 🧭 تجربه ⚠️ منفی)\n\n"
            .($lines->isEmpty() ? 'لوپری در لیست نیست.' : $lines->implode("\n\n"))
            .($rows->count() > self::SUMMARY_SIZE ? "\n\n…و ".($rows->count() - self::SUMMARY_SIZE).' نفر دیگه؛ همه توی داشبورد هستن.' : '')
            ."\n\nاین اعداد کمک تصمیم‌اند، نه خود تصمیم.\nداشبورد و فایل متنی کامل الان می‌رسن 👇", $buttons);

        $this->reports->sendPeriod($actor->chatId, $period);
    }
}
