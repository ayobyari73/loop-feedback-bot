<?php

namespace Tests\Feature;

use App\Services\Jalali;
use App\Services\Season;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeasonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['loop.season_start' => '2026-09-23', 'loop.timezone' => 'Asia/Tehran']);
    }

    /**
     * @return list<array{0: array{int, int, int}, 1: array{int, int, int}}>
     */
    public static function knownDates(): array
    {
        return [
            'nowruz 1405' => [[2026, 3, 21], [1405, 1, 1]],
            'first of mehr' => [[2026, 9, 23], [1405, 7, 1]],
            'aban 17' => [[2026, 11, 8], [1405, 8, 17]],
            'last day of azar' => [[2026, 12, 21], [1405, 9, 30]],
            'leap day esfand 30, 1403' => [[2025, 3, 20], [1403, 12, 30]],
            'last day of 1405 (29 days)' => [[2027, 3, 20], [1405, 12, 29]],
        ];
    }

    #[DataProvider('knownDates')]
    public function test_jalali_conversion_matches_known_dates_both_ways(array $gregorian, array $jalali): void
    {
        $this->assertSame($jalali, Jalali::fromGregorian(...$gregorian));
        $this->assertSame($gregorian, Jalali::toGregorian(...$jalali));
    }

    public function test_months_are_read_in_tehran_time(): void
    {
        $this->assertSame('1405/07/30', Jalali::format(Carbon::parse('2026-10-22 20:00:00', 'UTC')));
        $this->assertSame('1405/08/01', Jalali::format(Carbon::parse('2026-10-22 21:00:00', 'UTC')));
        $this->assertSame('آبان 1405', Jalali::monthName(Jalali::monthIndex(Carbon::parse('2026-11-08', 'Asia/Tehran'))));
        $this->assertSame('2026-10-23 00:00', Jalali::monthStart(1405 * 12 + 7)->format('Y-m-d H:i'));
    }

    public function test_a_season_is_three_jalali_months_ending_at_midnight_in_tehran(): void
    {
        $this->assertSame(0, Season::number(Carbon::parse('2026-12-21 23:59:00', 'Asia/Tehran')));
        $this->assertSame(1, Season::number(Carbon::parse('2026-12-22 00:00:00', 'Asia/Tehran')));
        $this->assertSame(1, Season::number(Carbon::parse('2026-12-21 21:00:00', 'UTC')));
    }

    public function test_days_before_the_first_season_belong_to_earlier_seasons(): void
    {
        $this->assertSame(0, Season::number(Carbon::parse('2026-09-23 00:00:00', 'Asia/Tehran')));
        $this->assertSame(-1, Season::number(Carbon::parse('2026-09-22 23:59:00', 'Asia/Tehran')));
        $this->assertSame(-2, Season::number(Carbon::parse('2026-06-21 12:00:00', 'Asia/Tehran')));
    }

    public function test_the_current_season_follows_the_tehran_clock_whatever_the_server_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-12-21 21:00:00', 'UTC'));

        $this->assertSame(1, Season::number());
    }

    public function test_labels_show_the_first_and_last_jalali_day_of_each_season(): void
    {
        $this->assertSame('فصل 1 (1405/07/01 تا 1405/09/30)', Season::label(0));
        $this->assertSame('فصل 2 (1405/10/01 تا 1405/12/29)', Season::label(1));
    }
}
