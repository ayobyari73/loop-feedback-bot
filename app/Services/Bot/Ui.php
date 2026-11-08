<?php

namespace App\Services\Bot;

use App\Models\Member;
use App\Services\Jalali;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;

/**
 * Shared building blocks for bot messages: the role-based bottom menu, looper pickers and small formatters.
 */
class Ui
{
    public const PAGE_SIZE = 8;

    /** @var array<string, string> Bottom menu button labels, keyed by the action they trigger. */
    public const BUTTONS = [
        'feedback' => '📝 ثبت فیدبک',
        'me' => '👤 پروفایل من',
        'addlooper' => '➕ افزودن لوپر',
        'loopers' => '👥 لوپرها',
        'reports' => '📊 گزارش‌ها',
        'broadcast' => '📢 ارسال پیام',
        'admins' => '👮 ادمین‌ها',
        'scoring' => '⚙️ امتیازدهی',
    ];

    private const SPARK = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    /**
     * @return list<list<string>>
     */
    public function menu(Actor $actor): array
    {
        $menu = [];

        if ($actor->member || $actor->isAdmin) {
            $menu[] = [self::BUTTONS['feedback'], self::BUTTONS['me']];
        }
        if ($actor->isAdmin) {
            $menu[] = [self::BUTTONS['addlooper'], self::BUTTONS['loopers']];
            $menu[] = [self::BUTTONS['reports'], self::BUTTONS['broadcast']];
        }
        if ($actor->isOwner) {
            $menu[] = [self::BUTTONS['admins'], self::BUTTONS['scoring']];
        }

        return $menu ?: [[self::BUTTONS['feedback']]];
    }

    /**
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function cancelButton(): array
    {
        return [[['text' => '❌ انصراف', 'callback_data' => 'x']]];
    }

    /**
     * @return Collection<int, Member>
     */
    public function activeMembersExcept(?Member $member = null): Collection
    {
        return Member::where('active', true)
            ->when($member, fn ($query) => $query->where('id', '!=', $member->id))
            ->orderBy('name')->orderBy('username')->get();
    }

    /**
     * A paginated list of looper buttons.
     *
     * @param  Collection<int, Member>  $people
     * @param  (Closure(Member): string)|null  $label
     * @param  list<list<array{text: string, callback_data: string}>>  $extraRows  Shown under the names.
     * @return array{0: string, 1: list<list<array{text: string, callback_data: string}>>}
     */
    public function picker(Collection $people, int $page, string $pickPrefix, string $pagePrefix, string $title, ?Closure $label = null, array $extraRows = [], bool $cancel = true): array
    {
        $label ??= fn (Member $person) => $person->name ?: $person->handle();
        $pages = max(1, (int) ceil($people->count() / self::PAGE_SIZE));
        $page = max(0, min($page, $pages - 1));

        $buttons = $people->forPage($page + 1, self::PAGE_SIZE)
            ->map(fn (Member $person) => ['text' => $label($person), 'callback_data' => "{$pickPrefix}:{$person->id}"])
            ->values()->chunk(2)->map(fn ($row) => $row->values()->all())->values()->all();

        $navigation = $this->navigation($page, $pages, fn (int $target) => "{$pagePrefix}:{$target}");
        if ($navigation) {
            $buttons[] = $navigation;
        }

        array_push($buttons, ...$extraRows);
        if ($cancel) {
            $buttons[] = $this->cancelButton()[0];
        }

        return [$title, $buttons];
    }

    /**
     * @param  Closure(int): string  $callbackFor
     * @return list<array{text: string, callback_data: string}>
     */
    public function navigation(int $page, int $pages, Closure $callbackFor): array
    {
        $navigation = [];
        if ($page > 0) {
            $navigation[] = ['text' => '◀️ قبلی', 'callback_data' => $callbackFor($page - 1)];
        }
        if ($page < $pages - 1) {
            $navigation[] = ['text' => 'بعدی ▶️', 'callback_data' => $callbackFor($page + 1)];
        }

        return $navigation;
    }

    /**
     * Makes Arabic and Persian spellings of the same letters compare equal.
     */
    public static function normalize(string $text): string
    {
        return mb_strtolower(strtr($text, ['ي' => 'ی', 'ك' => 'ک', "\u{200C}" => ' ']));
    }

    /**
     * Bars for weekly counts, oldest first. Wrapped in left-to-right marks so the oldest week stays on the left
     * even inside a right-to-left message.
     *
     * @param  list<int>  $values
     */
    public static function sparkline(array $values): string
    {
        $max = max($values ?: [0]);
        $bars = array_map(
            fn (int $value) => self::SPARK[$value === 0 || $max === 0 ? 0 : max(1, (int) round($value / $max * 7))],
            $values,
        );

        return "\u{200E}".implode('', $bars)."\u{200E}";
    }

    public static function date(CarbonInterface $date): string
    {
        return Jalali::format($date);
    }

    /**
     * Accepts Persian/Arabic digits and the Unicode minus sign, so "−۲" reads as -2.
     */
    public static function parseInteger(string $text): ?int
    {
        $text = strtr(trim($text), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '−' => '-', '–' => '-', '+' => '',
        ]);

        return preg_match('/^-?\d{1,3}$/', $text) ? (int) $text : null;
    }
}
