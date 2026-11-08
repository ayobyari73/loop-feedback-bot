<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Loop evaluates loopers in seasons of three Jalali months, counted from the Jalali month that contains
 * config('loop.season_start') (by default 1405/07/01, i.e. Mehr–Azar, Dey–Esfand, ...), in Tehran time.
 */
class Season
{
    public static function number(?CarbonInterface $at = null): int
    {
        $months = Jalali::monthIndex($at ?? now()) - self::firstMonth();

        return intdiv($months - ($months < 0 ? 2 : 0), 3);
    }

    public static function label(int $number): string
    {
        return sprintf('فصل %d (%s تا %s)', $number + 1, Jalali::format(self::start($number)), Jalali::format(self::end($number)->subDay()));
    }

    /**
     * Midnight (Tehran) on the first day of the season.
     */
    public static function start(int $number): Carbon
    {
        return Jalali::monthStart(self::firstMonth() + $number * 3);
    }

    /**
     * Midnight (Tehran) on the first day of the next season (exclusive end).
     */
    public static function end(int $number): Carbon
    {
        return Jalali::monthStart(self::firstMonth() + $number * 3 + 3);
    }

    private static function firstMonth(): int
    {
        return Jalali::monthIndex(Carbon::parse(config('loop.season_start'), config('loop.timezone')));
    }
}
