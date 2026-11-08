<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Member;
use App\Models\ScoreRule;
use App\Services\ProfileStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TalksToTheBot;
use Tests\TestCase;

class ScoringTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
    }

    private function points(Member $member): int
    {
        return app(ProfileStats::class)->forMember($member)['total']['points'];
    }

    private function setRule(string $cell, string $value): void
    {
        $this->say(self::OWNER_ID, '⚙️ امتیازدهی', 'boss');
        $this->click(self::OWNER_ID, "se:{$cell}", 'boss');
        $this->say(self::OWNER_ID, $value, 'boss');
    }

    public function test_default_rules_give_one_point_to_both_sides_of_positive_feedback_and_none_for_negative(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        Feedback::factory()->create(['from_member_id' => $alice->id, 'to_member_id' => $bob->id, 'kind' => 'knowledge']);
        Feedback::factory()->negative()->create(['from_member_id' => $alice->id, 'to_member_id' => $bob->id]);

        $this->assertSame(1, $this->points($alice));
        $this->assertSame(1, $this->points($bob));
    }

    public function test_the_owner_changes_a_rule_and_it_applies_to_past_feedback_too(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        Feedback::factory()->count(2)->create(['from_member_id' => $alice->id, 'to_member_id' => $bob->id, 'kind' => 'knowledge']);

        $this->setRule('knowledge:r', '3');

        $this->assertSame(3, ScoreRule::where('kind', 'knowledge')->value('receiver_points'));
        $this->assertSame(6, $this->points($bob));
        $this->assertSame(2, $this->points($alice));
        $this->assertMessageSentTo(self::OWNER_ID, 'روی همه‌ی فیدبک‌ها اعمال شد');
    }

    public function test_negative_points_with_persian_digits_and_unicode_minus_are_accepted(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        Feedback::factory()->negative()->create(['from_member_id' => $alice->id, 'to_member_id' => $bob->id]);

        $this->setRule('negative:r', '−۲');

        $this->assertSame(-2, $this->points($bob));
    }

    public function test_invalid_or_out_of_range_numbers_are_refused_and_asked_again(): void
    {
        $this->setRule('network:g', 'سه');
        $this->say(self::OWNER_ID, '11', 'boss');
        $this->assertSame(1, ScoreRule::where('kind', 'network')->value('giver_points'));
        $this->assertCount(2, $this->messagesSentTo(self::OWNER_ID, 'یه عدد صحیح'));

        $this->say(self::OWNER_ID, '4', 'boss');
        $this->assertSame(4, ScoreRule::where('kind', 'network')->value('giver_points'));
    }

    public function test_a_tampered_cell_is_ignored(): void
    {
        $this->click(self::OWNER_ID, 'se:bogus:g', 'boss');
        $this->say(self::OWNER_ID, '5', 'boss');

        $this->assertNull(ScoreRule::where('kind', 'bogus')->first());
    }

    public function test_pressing_a_menu_button_drops_a_half_finished_score_edit(): void
    {
        $this->click(self::OWNER_ID, 'se:network:g', 'boss');
        $this->say(self::OWNER_ID, '📊 گزارش‌ها', 'boss');
        $this->say(self::OWNER_ID, '7', 'boss');

        $this->assertSame(1, ScoreRule::where('kind', 'network')->value('giver_points'));
    }

    public function test_deleted_feedback_no_longer_counts(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $feedback = Feedback::factory()->create(['from_member_id' => $alice->id, 'to_member_id' => $bob->id]);

        $feedback->delete();

        $this->assertSame(0, $this->points($bob));
    }

    public function test_the_points_messages_follow_the_current_rules(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        ScoreRule::where('kind', 'experience')->update(['giver_points' => 2, 'receiver_points' => 5]);

        $this->submitFeedback($alice, $bob, 'experience', 'تجربه منتقل کرد');

        $this->assertMessageSentTo($alice->telegram_id, "امتیاز تو: \u{200E}+2");
        $this->assertMessageSentTo($bob->telegram_id, "امتیاز تو: \u{200E}+5");
    }
}
