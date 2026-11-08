<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Member;
use App\Services\Bot\Ui;
use App\Services\ProfileStats;
use App\Services\Season;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TalksToTheBot;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'Asia/Tehran'));
    }

    private function give(Member $from, Member $to, string $kind, ?int $season = null, ?Carbon $at = null): Feedback
    {
        return Feedback::factory()->create([
            'from_member_id' => $from->id,
            'to_member_id' => $to->id,
            'kind' => $kind,
            'season' => $season ?? Season::number(),
            'created_at' => $at ?? now(),
        ]);
    }

    public function test_the_profile_counts_all_four_indicators_received_and_given_for_the_season_and_all_time(): void
    {
        [$sara, $ali, $reza] = Member::factory()->count(3)->create();
        $this->give($ali, $sara, 'network');
        $this->give($reza, $sara, 'network');
        $this->give($ali, $sara, 'negative');
        $this->give($ali, $sara, 'knowledge', Season::number() - 1, now()->subMonths(4));
        $this->give($sara, $ali, 'experience');

        $stats = app(ProfileStats::class)->forMember($sara);

        $this->assertSame(['network' => 2, 'knowledge' => 0, 'experience' => 0, 'negative' => 1], $stats['season']['received']);
        $this->assertSame(['network' => 2, 'knowledge' => 1, 'experience' => 0, 'negative' => 1], $stats['total']['received']);
        $this->assertSame(['network' => 0, 'knowledge' => 0, 'experience' => 1, 'negative' => 0], $stats['total']['given']);
        $this->assertSame(2, $stats['total']['from_people']);
        $this->assertSame(1, $stats['total']['to_people']);
        $this->assertSame(3, $stats['season']['points']);
        $this->assertSame(4, $stats['total']['points']);
    }

    public function test_repeated_feedback_from_one_person_counts_as_one_person(): void
    {
        [$sara, $ali] = Member::factory()->count(2)->create();
        $this->give($ali, $sara, 'network');
        $this->give($ali, $sara, 'knowledge');
        $this->give($ali, $sara, 'network');

        $stats = app(ProfileStats::class)->forMember($sara);

        $this->assertSame(3, array_sum($stats['total']['received']));
        $this->assertSame(1, $stats['total']['from_people']);
    }

    public function test_the_weekly_trend_buckets_activity_by_tehran_weeks_and_reports_growth(): void
    {
        [$sara, $ali] = Member::factory()->count(2)->create();
        $this->give($ali, $sara, 'network', at: now());
        $this->give($sara, $ali, 'network', at: now()->subDays(2));
        $this->give($ali, $sara, 'knowledge', at: now()->subWeeks(5));
        $this->give($ali, $sara, 'knowledge', at: now()->subWeeks(20));

        $stats = app(ProfileStats::class);
        $weekly = $stats->forMember($sara)['weekly'];

        $this->assertCount(ProfileStats::WEEKS, $weekly);
        $this->assertSame(2, $weekly[11]);
        $this->assertSame(1, $weekly[6]);
        $this->assertSame(3, array_sum($weekly));
        $this->assertSame(100, $stats->growth($weekly));
    }

    public function test_growth_is_unknown_without_earlier_activity(): void
    {
        $this->assertNull(app(ProfileStats::class)->growth([0, 0, 0, 0, 0, 0, 0, 0, 1, 2, 0, 3]));
    }

    public function test_the_sparkline_keeps_zero_weeks_flat_and_the_busiest_week_full(): void
    {
        $this->assertSame("\u{200E}▁▁█▅\u{200E}", Ui::sparkline([0, 0, 8, 5]));
        $this->assertSame("\u{200E}▁▁\u{200E}", Ui::sparkline([0, 0]));
    }

    public function test_my_profile_shows_points_indicators_people_and_trend(): void
    {
        [$sara, $ali] = Member::factory()->count(2)->create();
        $this->give($ali, $sara, 'network');

        $this->sayAs($sara, '👤 پروفایل من');

        $text = $this->lastTextSentTo($sara->telegram_id);
        $this->assertStringContainsString('⭐ امتیاز: <b>1</b> · 1', $text);
        $this->assertStringContainsString('🌐 شبکه: <b>1</b> · 1', $text);
        $this->assertStringContainsString('از <b>1</b> نفر مختلف', $text);
        $this->assertStringContainsString('📈 روند فعالیت', $text);
    }

    public function test_a_looper_sees_their_own_feedback_lists_without_delete_buttons(): void
    {
        [$sara, $ali] = Member::factory()->count(2)->create();
        $this->give($ali, $sara, 'knowledge')->update(['description' => 'آموزش لاراول']);

        $this->clickAs($sara, "fl:{$sara->id}:r:0");

        $this->assertStringContainsString('آموزش لاراول', $this->lastTextSentTo($sara->telegram_id));
        $this->assertEmpty(array_filter(array_column($this->lastButtonsSentTo($sara->telegram_id), 'callback_data'), fn (string $data) => str_starts_with($data, 'del:')));
    }

    public function test_a_looper_cannot_open_someone_elses_profile_or_feedback(): void
    {
        [$sara, $ali] = Member::factory()->count(2)->create();
        $this->give($sara, $ali, 'knowledge')->update(['description' => 'محرمانه']);

        $this->clickAs($sara, "pf:{$ali->id}");
        $this->clickAs($sara, "fl:{$ali->id}:r:0");

        $this->assertCount(0, $this->messagesSentTo($sara->telegram_id, '', true));
    }

    public function test_an_admin_opens_any_profile_from_the_looper_list_and_sees_who_gave_what(): void
    {
        $admin = $this->admin();
        [$sara, $ali] = Member::factory()->count(2)->create();
        $this->give($ali, $sara, 'experience')->update(['description' => 'در مذاکره کمکم کرد']);

        $this->sayAs($admin, '👥 لوپرها');
        $this->assertContains("pf:{$sara->id}", array_column($this->lastButtonsSentTo($admin->telegram_id), 'callback_data'));

        $this->clickAs($admin, "pf:{$sara->id}");
        $this->assertStringContainsString($sara->name, $this->lastTextSentTo($admin->telegram_id));

        $this->clickAs($admin, "fl:{$sara->id}:r:0");
        $text = $this->lastTextSentTo($admin->telegram_id);
        $this->assertStringContainsString('در مذاکره کمکم کرد', $text);
        $this->assertStringContainsString('از '.e($ali->name), $text);
    }

    public function test_an_admin_deletes_a_feedback_after_confirming_and_points_update_at_once(): void
    {
        $admin = $this->admin();
        [$sara, $ali] = Member::factory()->count(2)->create();
        $feedback = $this->give($ali, $sara, 'network');

        $this->clickAs($admin, "del:{$feedback->id}:{$sara->id}:r:0");
        $this->assertNotSoftDeleted($feedback);

        $this->clickAs($admin, "dly:{$feedback->id}:{$sara->id}:r:0");

        $this->assertSoftDeleted($feedback);
        $this->assertSame($admin->telegram_id, Feedback::withTrashed()->find($feedback->id)->deleted_by);
        $this->assertSame(0, app(ProfileStats::class)->forMember($sara)['total']['points']);
    }

    public function test_a_looper_cannot_delete_feedback_even_on_their_own_profile(): void
    {
        [$sara, $ali] = Member::factory()->count(2)->create();
        $feedback = $this->give($ali, $sara, 'negative');

        $this->clickAs($sara, "dly:{$feedback->id}:{$sara->id}:r:0");

        $this->assertNotSoftDeleted($feedback);
    }

    public function test_feedback_lists_are_paginated(): void
    {
        $admin = $this->admin();
        [$sara, $ali] = Member::factory()->count(2)->create();
        Feedback::factory()->count(10)->create(['from_member_id' => $ali->id, 'to_member_id' => $sara->id]);

        $this->clickAs($admin, "fl:{$sara->id}:r:0");
        $this->assertContains("fl:{$sara->id}:r:1", array_column($this->lastButtonsSentTo($admin->telegram_id), 'callback_data'));

        $this->clickAs($admin, "fl:{$sara->id}:r:1");
        $this->assertStringContainsString('صفحه‌ی 2 از 2', $this->lastTextSentTo($admin->telegram_id));
    }

    public function test_the_season_report_shows_points_indicators_and_distinct_people_ranked_by_points(): void
    {
        $admin = $this->admin(['name' => 'ادمین']);
        $sara = Member::factory()->create(['name' => 'سارا']);
        $ali = Member::factory()->create(['name' => 'علی']);
        $this->give($ali, $sara, 'network');
        $this->give($ali, $sara, 'knowledge');

        $this->sayAs($admin, '/season');

        $text = $this->lastTextSentTo($admin->telegram_id);
        $this->assertStringContainsString("• <b>سارا</b> — ⭐ 2\n   📥 🌐1 📚1 🧭0 ⚠️0 · از 1 نفر", $text);
        $this->assertStringContainsString("<b>علی</b> — ⭐ 2\n   📥 🌐0 📚0 🧭0 ⚠️0 · از 0 نفر\n   📤 🌐1 📚1 🧭0 ⚠️0 · به 1 نفر", $text);
        $this->assertLessThan(mb_strpos($text, '<b>ادمین</b>'), mb_strpos($text, '<b>سارا</b>'));
        $this->assertStringContainsString('فیدبک‌های این فصل: <b>2</b>', $text);
        $this->assertNotContains('sr:-1', array_column($this->lastButtonsSentTo($admin->telegram_id), 'callback_data'));
    }
}
