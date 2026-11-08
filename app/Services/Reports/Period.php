<?php

namespace App\Services\Reports;

use App\Services\Jalali;
use App\Services\Season;
use Carbon\Carbon;

/**
 * A reporting window: one Jalali month or one season, as [start, end) in Tehran time.
 */
final class Period
{
    private function __construct(
        public readonly string $type,
        public readonly int $number,
        public readonly string $title,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $key,
    ) {}

    public static function month(int $index): self
    {
        return new self('month', $index, 'ماه '.Jalali::monthName($index), Jalali::monthStart($index), Jalali::monthStart($index + 1), Jalali::monthKey($index));
    }

    public static function currentMonth(): self
    {
        return self::month(Jalali::monthIndex(now()));
    }

    public static function season(int $number): self
    {
        return new self('season', $number, Season::label($number), Season::start($number), Season::end($number), 'season-'.($number + 1));
    }

    public static function currentSeason(): self
    {
        return self::season(Season::number());
    }

    public function previous(): self
    {
        return $this->type === 'month' ? self::month($this->number - 1) : self::season($this->number - 1);
    }

    public function next(): self
    {
        return $this->type === 'month' ? self::month($this->number + 1) : self::season($this->number + 1);
    }

    public function isCurrentOrFuture(): bool
    {
        return $this->end->gt(now());
    }

    /**
     * "ماه" or "فصل", for phrases like «فیدبک‌های این ماه».
     */
    public function noun(): string
    {
        return $this->type === 'month' ? 'ماه' : 'فصل';
    }

    public function range(): string
    {
        return Jalali::format($this->start).' تا '.Jalali::format($this->end->copy()->subDay());
    }

    /**
     * Callback data that reopens this period's report.
     */
    public function callback(): string
    {
        return 'rp:'.($this->type === 'month' ? 'm' : 's').':'.$this->number;
    }
}
