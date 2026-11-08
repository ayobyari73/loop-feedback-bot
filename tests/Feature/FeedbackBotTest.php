<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Member;
use App\Services\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TalksToTheBot;
use Tests\TestCase;

class FeedbackBotTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
    }

    public function test_person_outside_the_list_is_turned_away(): void
    {
        $this->say(5, '/start', 'stranger');

        $this->assertMessageSentTo(5, 'توی لیست لوپرها نیست');
        $this->assertSame(0, Member::count());
    }

    public function test_admin_adds_loopers_and_a_looper_is_matched_by_username_on_first_start(): void
    {
        $this->say(self::OWNER_ID, "/addlooper\n@alice_loop علی‌اکبر\nbad", 'boss');

        $this->assertMessageSentTo(self::OWNER_ID, 'به لیست لوپرها اضافه شد');
        $this->assertMessageSentTo(self::OWNER_ID, 'یوزرنیم معتبر نداشتن');

        $this->say(11, '/start', 'Alice_Loop');

        $member = Member::where('username', 'alice_loop')->sole();
        $this->assertSame(11, $member->telegram_id);
        $this->assertSame('علی‌اکبر', $member->name);
        $this->assertMessageSentTo(11, 'خوش اومدی');
    }

    public function test_admin_adds_loopers_through_the_guided_button_flow_in_several_formats(): void
    {
        $admin = $this->admin();

        $this->sayAs($admin, '➕ افزودن لوپر');
        $this->assertMessageSentTo($admin->telegram_id, 'یوزرنیم تلگرام و اسم لوپر');

        $this->sayAs($admin, "علی رضایی @ali_rezaei\n@sara_ahmadi سارا احمدی\nhttps://t.me/reza_x Reza K\nmreza_y");

        $this->assertSame('علی رضایی', Member::where('username', 'ali_rezaei')->sole()->name);
        $this->assertSame('سارا احمدی', Member::where('username', 'sara_ahmadi')->sole()->name);
        $this->assertSame('Reza K', Member::where('username', 'reza_x')->sole()->name);
        $this->assertNull(Member::where('username', 'mreza_y')->sole()->name);
    }

    public function test_admin_input_without_a_username_asks_again_instead_of_failing(): void
    {
        $this->say(self::OWNER_ID, '➕ افزودن لوپر', 'boss');
        $this->say(self::OWNER_ID, 'فقط یک اسم', 'boss');

        $this->assertMessageSentTo(self::OWNER_ID, 'تشخیص داده نشد');
        $this->assertSame(0, Member::count());

        $this->say(self::OWNER_ID, 'فقط یک اسم @name_ok', 'boss');
        $this->assertSame('فقط یک اسم', Member::sole()->name);
    }

    public function test_owner_outside_the_list_can_add_himself_with_one_tap(): void
    {
        $this->say(self::OWNER_ID, '📝 ثبت فیدبک', 'boss');
        $this->assertMessageSentTo(self::OWNER_ID, 'اسم خودت هم توی لیست');

        $this->click(self::OWNER_ID, 'selfadd', 'boss');

        $this->assertSame(self::OWNER_ID, Member::sole()->telegram_id);
    }

    public function test_feedback_is_shown_as_a_preview_and_only_saved_after_confirming(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'k:knowledge');
        $this->sayAs($alice, 'یاد داد چطور ایندکس بزنم');

        $this->assertSame(0, Feedback::count());
        $this->assertMessageSentTo($alice->telegram_id, 'پیش‌نمایش');
        $this->assertNothingSentTo($bob->telegram_id);

        $this->clickAs($alice, 'ok');

        $feedback = Feedback::sole();
        $this->assertSame('knowledge', $feedback->kind);
        $this->assertSame(Season::number(), $feedback->season);
        $this->assertSame('idle', $alice->fresh()->state);
    }

    public function test_a_confirmed_feedback_reaches_the_looper_at_once_with_the_sender_name_and_points(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();

        $feedback = $this->submitFeedback($alice, $bob, 'network', 'من رو به یک سرمایه‌گذار وصل کرد');

        $this->assertNotNull($feedback->fresh()->delivered_at);
        $this->assertMessageSentTo($bob->telegram_id, $alice->name);
        $this->assertMessageSentTo($bob->telegram_id, 'من رو به یک سرمایه‌گذار وصل کرد');
        $this->assertMessageSentTo($bob->telegram_id, "امتیاز تو: \u{200E}+1");
        $this->assertMessageSentTo($alice->telegram_id, "امتیاز تو: \u{200E}+1");
    }

    public function test_cancelling_at_the_preview_saves_nothing(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'k:knowledge');
        $this->sayAs($alice, 'منصرف شدم');

        $this->clickAs($alice, 'x');
        $this->clickAs($alice, 'ok');

        $this->assertSame(0, Feedback::count());
    }

    public function test_negative_feedback_asks_the_other_side_to_resolve_it_and_alerts_the_owner_and_admins(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $admin = $this->admin();

        $this->submitFeedback($alice, $bob, 'negative', 'قول داد و جواب نداد');

        $this->assertMessageSentTo($bob->telegram_id, 'حل‌وفصل');
        $this->assertMessageSentTo($bob->telegram_id, 'قول داد و جواب نداد');
        $this->assertMessageSentTo(self::OWNER_ID, 'فیدبک منفی ثبت شد');
        $this->assertMessageSentTo($admin->telegram_id, 'فیدبک منفی ثبت شد');
    }

    public function test_a_negative_alert_is_not_sent_to_an_admin_who_is_involved(): void
    {
        $admin = $this->admin();
        $bob = Member::factory()->create();

        $this->submitFeedback($admin, $bob, 'negative', 'مشکل داشتیم');

        $this->assertCount(0, $this->messagesSentTo($admin->telegram_id, 'فیدبک منفی ثبت شد'));
    }

    public function test_positive_feedback_does_not_alert_the_staff(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();

        $this->submitFeedback($alice, $bob, 'experience', 'تجربه‌ی خوبی بود');

        $this->assertNothingSentTo(self::OWNER_ID);
    }

    public function test_feedback_for_a_looper_who_has_not_started_the_bot_is_delivered_on_their_first_start(): void
    {
        $alice = Member::factory()->create();
        $carol = Member::factory()->notStarted()->create(['username' => 'carol_loop']);

        $feedback = $this->submitFeedback($alice, $carol, 'knowledge', 'آموزش داد');
        $this->assertNull($feedback->fresh()->delivered_at);
        $this->assertMessageSentTo($alice->telegram_id, 'هنوز ربات رو استارت نکرده');

        $this->say(77, '/start', 'carol_loop');

        $this->assertNotNull($feedback->fresh()->delivered_at);
        $this->assertMessageSentTo(77, 'آموزش داد');
    }

    public function test_the_same_pair_can_repeat_feedback_without_any_limit(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();

        $this->submitFeedback($alice, $bob, 'network', 'بار اول');
        $this->submitFeedback($alice, $bob, 'network', 'بار دوم');

        $this->assertSame(2, Feedback::where('from_member_id', $alice->id)->count());
    }

    public function test_a_looper_cannot_pick_themselves_or_a_removed_looper(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        Member::factory()->create();
        $bob->update(['active' => false]);

        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$alice->id}");
        $this->clickAs($alice, "to:{$bob->id}");

        $this->assertSame('choose_target', $alice->fresh()->state);
    }

    public function test_description_that_is_too_short_is_rejected_and_the_state_is_kept(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'k:network');

        $this->sayAs($alice, 'ab');

        $this->assertSame('await_description', $alice->fresh()->state);
    }

    public function test_an_abandoned_conversation_expires_so_a_later_message_never_becomes_feedback(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'k:network');

        $this->travel(Member::CONVERSATION_MINUTES + 1)->minutes();
        $this->sayAs($alice, 'سلام، فردا جلسه داریم؟');
        $this->clickAs($alice, 'ok');

        $this->assertSame(0, Feedback::count());
        $this->assertSame('idle', $alice->fresh()->state);
        $this->assertMessageSentTo($alice->telegram_id, 'منقضی شده بود');
    }

    public function test_a_conversation_within_the_time_limit_continues_normally(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'k:network');

        $this->travel(Member::CONVERSATION_MINUTES - 1)->minutes();
        $this->sayAs($alice, 'هنوز معتبره');
        $this->clickAs($alice, 'ok');

        $this->assertSame(1, Feedback::count());
    }

    public function test_user_text_is_escaped_in_messages(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();

        $this->submitFeedback($alice, $bob, 'knowledge', '<b>bold</b> & more');

        $this->assertMessageSentTo($bob->telegram_id, '&lt;b&gt;bold&lt;/b&gt; &amp; more');
    }

    public function test_menu_buttons_work_like_commands(): void
    {
        [$alice] = Member::factory()->count(2)->create();

        $this->sayAs($alice, '📝 ثبت فیدبک');
        $this->sayAs($alice, '👤 پروفایل من');

        $this->assertMessageSentTo($alice->telegram_id, 'با کدوم لوپر همکاری داشتی');
        $this->assertMessageSentTo($alice->telegram_id, '📥 <b>گرفته</b>');
    }

    public function test_cancel_button_ends_the_conversation(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");

        $this->clickAs($alice, 'x');

        $this->assertSame('idle', $alice->fresh()->state);
    }

    public function test_free_text_outside_a_conversation_points_to_the_menu_instead_of_failing(): void
    {
        $alice = Member::factory()->create();

        $this->sayAs($alice, 'سلام');

        $this->assertMessageSentTo($alice->telegram_id, 'از دکمه‌های منوی پایین');
    }

    public function test_every_line_is_forced_right_to_left_so_usernames_do_not_scramble_persian_text(): void
    {
        $this->say(5, '/id');

        Http::assertSent(fn (Request $request) => str_starts_with($request['text'], "\u{200F}"));
    }

    public function test_a_looper_without_a_name_is_shown_by_a_handle_that_keeps_its_at_sign_in_front(): void
    {
        $alice = Member::factory()->create();
        $bare = Member::factory()->create(['name' => null, 'username' => 'bare_user']);
        $this->sayAs($alice, '/feedback');

        $this->clickAs($alice, "to:{$bare->id}");

        $this->assertMessageSentTo($alice->telegram_id, "\u{200E}@bare_user\u{200E}");
    }

    public function test_removed_looper_loses_access_but_history_is_kept(): void
    {
        $alice = Member::factory()->create();
        Feedback::factory()->create(['from_member_id' => $alice->id]);

        $this->say(self::OWNER_ID, "/remove @{$alice->username}", 'boss');
        $this->sayAs($alice, '/feedback');

        $this->assertFalse($alice->fresh()->active);
        $this->assertSame(1, Feedback::where('from_member_id', $alice->id)->count());
        $this->assertMessageSentTo($alice->telegram_id, 'توی لیست لوپرها نیست');
    }
}
