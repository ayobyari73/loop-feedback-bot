<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TalksToTheBot;
use Tests\TestCase;

class BotSecurityTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
    }

    public function test_a_looper_cannot_trigger_any_admin_or_owner_button(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $feedback = Feedback::factory()->create(['to_member_id' => $bob->id]);

        foreach (['addl', 'lp:0', 'rml:0', "rm:{$bob->id}", "rmy:{$bob->id}", 'sr:0', "pf:{$bob->id}", "del:{$feedback->id}:{$bob->id}:r:0", "dly:{$feedback->id}:{$bob->id}:r:0", 'ada:0', "adp:{$alice->id}", 'se:network:g'] as $data) {
            $this->clickAs($alice, $data);
        }
        $this->sayAs($alice, 'اسم @sneaky_user');

        $this->assertTrue($bob->fresh()->active);
        $this->assertFalse($alice->fresh()->is_admin);
        $this->assertNotSoftDeleted($feedback);
        $this->assertNull(Member::where('username', 'sneaky_user')->first());
        $this->assertAnswered('فقط از دست ادمین');
        $this->assertCount(0, $this->messagesSentTo($alice->telegram_id, 'پروفایل', true));
    }

    public function test_a_looper_gets_no_access_to_admin_or_owner_text_commands(): void
    {
        $alice = Member::factory()->create();

        foreach (['/addlooper @hacker_one', '/loopers', '/season', '/remove @someone_x', '/admins', '/scoring'] as $command) {
            $this->sayAs($alice, $command);
        }

        $this->assertSame(1, Member::count());
        $this->assertCount(6, $this->messagesSentTo($alice->telegram_id, 'برای تو باز نیست'));
    }

    public function test_forged_callbacks_cannot_skip_steps_or_pick_unavailable_loopers(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();

        $this->clickAs($alice, 'k:negative');
        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'ok');
        $this->assertSame('idle', $alice->fresh()->state);

        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, 'to:999999');
        $this->clickAs($alice, 'k:negative');
        $this->assertSame('choose_target', $alice->fresh()->state);

        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'k:bogus');
        $this->assertSame('choose_kind', $alice->fresh()->state);
        $this->assertSame(0, Feedback::count());
    }

    public function test_malformed_callback_data_is_ignored(): void
    {
        $alice = Member::factory()->create();

        $this->clickAs($alice, 'zzz');
        $this->clickAs($alice, '');
        $this->clickAs($alice, 'to:abc');
        $this->clickAs($alice, 'fl:');
        $this->clickAs($alice, 'dly:x:y');

        $this->assertSame('idle', $alice->fresh()->state);
    }

    public function test_html_in_names_and_descriptions_cannot_inject_markup(): void
    {
        $this->say(self::OWNER_ID, '/addlooper <i>Mallory</i> @mallory_x', 'boss');
        $mallory = Member::where('username', 'mallory_x')->sole();
        $this->click(self::OWNER_ID, "pf:{$mallory->id}", 'boss');

        $this->assertMessageSentTo(self::OWNER_ID, '&lt;i&gt;Mallory&lt;/i&gt;');
        Http::assertNotSent(fn (Request $request) => str_contains($request['text'] ?? '', '<i>Mallory</i>'));
    }

    public function test_messages_in_groups_are_ignored(): void
    {
        $this->sendMessageUpdate([
            'text' => '/start',
            'chat' => ['id' => -100123, 'type' => 'supergroup'],
            'from' => ['id' => self::OWNER_ID, 'username' => 'boss', 'first_name' => 'Boss'],
        ]);

        Http::assertNothingSent();
    }

    public function test_commands_addressed_to_the_bot_by_name_work(): void
    {
        $this->say(self::OWNER_ID, '/start@loop_feedback1_bot', 'boss');

        $this->assertMessageSentTo(self::OWNER_ID, 'تو اونر هستی');
    }

    public function test_description_length_limits_are_enforced_in_characters_not_bytes(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $this->sayAs($alice, '/feedback');
        $this->clickAs($alice, "to:{$bob->id}");
        $this->clickAs($alice, 'k:knowledge');

        $this->sayAs($alice, str_repeat('ی', 1001));
        $this->assertSame('await_description', $alice->fresh()->state);

        $this->sayAs($alice, str_repeat('ی', 1000));
        $this->clickAs($alice, 'ok');
        $this->assertSame(1000, mb_strlen(Feedback::sole()->description));
    }

    public function test_persian_text_with_emoji_and_quotes_is_stored_exactly(): void
    {
        [$alice, $bob] = Member::factory()->count(2)->create();
        $text = "من رو به «علی» وصل کرد 🙏 & 'quote' \"double\" <tag>";

        $feedback = $this->submitFeedback($alice, $bob, 'network', $text);

        $this->assertSame($text, $feedback->description);
    }
}
