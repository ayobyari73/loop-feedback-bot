<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Solar Hijri (Jalali) calendar conversions, in the Tehran timezone.
 *
 * Months are also addressed by a single "month index" (year * 12 + month - 1), which makes
 * month arithmetic (previous month, three months later) plain integer math.
 */
class Jalali
{
    public const MONTHS = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    /**
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $daysBeforeMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $daysBeforeMonth[$gm - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        return $days < 186
            ? [$jy, 1 + intdiv($days, 31), 1 + $days % 31]
            : [$jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
    }

    /**
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + 365 * $jy + intdiv($jy, 33) * 8 + intdiv($jy % 33 + 3, 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;
        $monthLengths = [31, ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 1;
        foreach ($monthLengths as $length) {
            if ($gd <= $length) {
                break;
            }
            $gd -= $length;
            $gm++;
        }

        return [$gy, $gm, $gd];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function of(CarbonInterface $date): array
    {
        $local = $date->copy()->setTimezone(config('loop.timezone'));

        return self::fromGregorian($local->year, $local->month, $local->day);
    }

    public static function format(CarbonInterface $date): string
    {
        [$y, $m, $d] = self::of($date);

        return sprintf('%04d/%02d/%02d', $y, $m, $d);
    }

    public static function monthIndex(CarbonInterface $date): int
    {
        [$y, $m] = self::of($date);

        return $y * 12 + $m - 1;
    }

    /**
     * Midnight (Tehran) on the first day of the month with this index.
     */
    public static function monthStart(int $index): Carbon
    {
        [$gy, $gm, $gd] = self::toGregorian(intdiv($index, 12), $index % 12 + 1, 1);

        return Carbon::create($gy, $gm, $gd, 0, 0, 0, config('loop.timezone'));
    }

    public static function monthName(int $index): string
    {
        return self::MONTHS[$index % 12 + 1].' '.intdiv($index, 12);
    }

    /**
     * "1405-08", safe for file names.
     */
    public static function monthKey(int $index): string
    {
        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }
}
