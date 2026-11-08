<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Member;
use App\Services\FeedbackBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\TalksToTheBot;
use Tests\TestCase;

class BotResilienceTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
    }

    public function test_feedback_stays_undelivered_when_the_looper_blocked_the_bot_and_is_retried_on_their_next_start(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->blockedChats = [$bob->telegram_id];

        $feedback = $this->submitFeedback($alice, $bob, 'knowledge', 'آموزش داد');

        $this->assertNull($feedback->fresh()->delivered_at);
        $this->assertMessageSentTo($alice->telegram_id, 'نرسید');

        $this->blockedChats = [];
        $this->sayAs($bob, '/start');

        $this->assertNotNull($feedback->fresh()->delivered_at);
        $this->assertMessageSentTo($bob->telegram_id, 'آموزش داد');
    }

    public function test_a_telegram_outage_loses_no_data_and_never_throws(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->telegramOffline = true;

        $feedback = $this->submitFeedback($alice, $bob, 'experience', 'در زمان قطعی ثبت شد');

        $this->assertNull($feedback->fresh()->delivered_at);
        $this->assertSame('idle', $alice->fresh()->state);
    }

    public function test_the_bot_token_never_reaches_the_logs(): void
    {
        config(['services.telegram.token' => '123456:SECRET-token']);
        $this->telegramOffline = true;
        Log::spy();

        $this->say(5, '/id');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'bot***') && ! str_contains($message, 'SECRET'));
    }

    public function test_a_photo_while_waiting_for_the_description_gets_a_helpful_reply_and_keeps_the_conversation(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'k:network');

        $this->sendMessageUpdate([
            'photo' => [['file_id' => 'x']],
            'chat' => ['id' => $alice->telegram_id, 'type' => 'private'],
            'from' => ['id' => $alice->telegram_id, 'username' => $alice->username, 'first_name' => 'Tester'],
        ]);

        $this->assertMessageSentTo($alice->telegram_id, 'فقط پیام متنی');
        $this->assertSame('await_description', $alice->fresh()->state);
    }

    public function test_a_looper_removed_while_someone_was_writing_about_them_is_not_rated(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'k:knowledge');
        $this->sayAs($alice, 'قبل از حذف');

        $bob->update(['active' => false]);
        $this->clickAs($alice, 'ok');

        $this->assertSame(0, Feedback::count());
        $this->assertMessageSentTo($alice->telegram_id, 'منقضی شده');
    }

    public function test_a_negative_alert_reaches_the_other_staff_even_if_one_admin_blocked_the_bot(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $blockedAdmin = $this->admin();
        $this->blockedChats = [$blockedAdmin->telegram_id];

        $this->submitFeedback($alice, $bob, 'negative', 'یک مشکل');

        $this->assertMessageSentTo(self::OWNER_ID, 'فیدبک منفی ثبت شد');
    }

    public function test_a_conversation_survives_a_restart_of_the_bot_process(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");

        app()->forgetInstance(FeedbackBot::class);
        $this->bot = app(FeedbackBot::class);

        $this->clickAs($alice, 'k:experience');
        $this->sayAs($alice, 'بعد از ری‌استارت');
        $this->clickAs($alice, 'ok');

        $this->assertSame('بعد از ری‌استارت', Feedback::sole()->description);
    }

    public function test_a_looper_who_renamed_their_username_is_still_recognised_and_the_new_name_is_kept(): void
    {
        $member = Member::factory()->create(['username' => 'old_name_1']);

        $this->say($member->telegram_id, '/me', 'new_name_1');

        $this->assertSame('new_name_1', $member->fresh()->username);
        $this->assertMessageSentTo($member->telegram_id, '📥 <b>گرفته</b>');
    }

    public function test_a_renamed_username_that_belongs_to_someone_else_is_not_taken_over(): void
    {
        $member = Member::factory()->create(['username' => 'old_name_1']);
        Member::factory()->create(['username' => 'taken_name_1']);

        $this->say($member->telegram_id, '/me', 'taken_name_1');

        $this->assertSame('old_name_1', $member->fresh()->username);
    }

    public function test_a_person_without_a_telegram_username_is_told_how_to_fix_it(): void
    {
        $this->say(5, '/start', null);

        $this->assertMessageSentTo(5, 'یوزرنیم نداری');
    }
}
