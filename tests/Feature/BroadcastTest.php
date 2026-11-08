<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\Member;
use App\Models\SendGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TalksToTheBot;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheBot;

    private Member $admin;

    private Member $alice;

    private Member $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
        config(['loop.broadcast_delay_ms' => 0]);
        $this->admin = $this->admin(['name' => 'ادمین']);
        $this->alice = Member::factory()->create(['name' => 'آلیس']);
        $this->bob = Member::factory()->create(['name' => 'بهرام']);
    }

    private function compose(string $audienceCallback, string $text): void
    {
        $this->sayAs($this->admin, '📢 ارسال پیام');
        $this->clickAs($this->admin, $audienceCallback);
        $this->sayAs($this->admin, $text);
    }

    /**
     * @return list<Request>
     */
    private function copiesTo(int $chatId): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'copyMessage') && $request['chat_id'] === $chatId)
            ->values()->all();
    }

    public function test_a_message_to_everyone_is_previewed_then_sent_and_reported(): void
    {
        $carol = Member::factory()->notStarted()->create(['name' => 'کارول']);

        $this->compose('bt:all', 'جلسه‌ی فردا ساعت ۱۰ برگزار می‌شه');

        $this->assertMessageSentTo($this->admin->telegram_id, 'گیرنده‌ها پیام رو دقیقاً این‌طوری می‌بینن');
        $this->assertMessageSentTo($this->admin->telegram_id, 'همه‌ی لوپرها</b> (3 نفر)');
        $this->assertContains('✅ ارسال برای 3 نفر', array_column($this->lastButtonsSentTo($this->admin->telegram_id), 'text'));
        $this->assertNothingSentTo($this->alice->telegram_id);

        $this->clickAs($this->admin, 'bsend');

        foreach ([$this->alice, $this->bob] as $member) {
            $this->assertMessageSentTo($member->telegram_id, '📢 <b>پیام تیم لوپ</b>');
            $this->assertMessageSentTo($member->telegram_id, 'جلسه‌ی فردا ساعت ۱۰ برگزار می‌شه');
        }
        $this->assertMessageSentTo($this->admin->telegram_id, 'پیام به <b>2</b> نفر از 3 نفر رسید');
        $this->assertMessageSentTo($this->admin->telegram_id, 'هنوز ربات رو استارت نکردن و پیام بهشون نرسید: کارول');
        $this->assertSame(['همه‌ی لوپرها', 3, 2], [Broadcast::sole()->audience, Broadcast::sole()->recipients, Broadcast::sole()->delivered]);
        $this->assertCount(1, $this->messagesSentTo($this->admin->telegram_id, '📢 <b>پیام تیم لوپ</b>'), 'Only the preview, the sender is not a recipient.');
        $this->assertNotNull($carol);
    }

    public function test_recipients_do_not_see_who_sent_the_message(): void
    {
        $this->compose('bt:all', 'سلام به همه');
        $this->clickAs($this->admin, 'bsend');

        $this->assertCount(0, $this->messagesSentTo($this->alice->telegram_id, 'ادمین'));
    }

    public function test_a_message_to_one_person_reaches_only_them(): void
    {
        $this->sayAs($this->admin, '📢 ارسال پیام');
        $this->clickAs($this->admin, 'bt:one');
        $this->sayAs($this->admin, 'بهر');
        $this->assertContains("bo:{$this->bob->id}", array_column($this->lastButtonsSentTo($this->admin->telegram_id), 'callback_data'));

        $this->clickAs($this->admin, "bo:{$this->bob->id}");
        $this->sayAs($this->admin, 'فایل جلسه رو بفرست');
        $this->clickAs($this->admin, 'bsend');

        $this->assertMessageSentTo($this->bob->telegram_id, 'فایل جلسه رو بفرست');
        $this->assertNothingSentTo($this->alice->telegram_id);
    }

    public function test_the_owner_builds_a_send_group_and_an_admin_messages_only_its_members(): void
    {
        $dave = Member::factory()->create(['name' => 'داوود']);

        $this->say(self::OWNER_ID, '📢 ارسال پیام', 'boss');
        $this->click(self::OWNER_ID, 'gm', 'boss');
        $this->click(self::OWNER_ID, 'gn', 'boss');
        $this->say(self::OWNER_ID, 'تیم فروش', 'boss');
        $group = SendGroup::where('name', 'تیم فروش')->sole();
        $this->click(self::OWNER_ID, "gt:{$group->id}:0:{$this->alice->id}", 'boss');
        $this->click(self::OWNER_ID, "gt:{$group->id}:0:{$this->bob->id}", 'boss');
        $this->click(self::OWNER_ID, "gt:{$group->id}:0:{$dave->id}", 'boss');
        $this->click(self::OWNER_ID, "gt:{$group->id}:0:{$dave->id}", 'boss');
        $this->click(self::OWNER_ID, 'gm', 'boss');

        $this->assertEqualsCanonicalizing([$this->alice->id, $this->bob->id], $group->members()->pluck('members.id')->all());

        $this->compose("bg:{$group->id}", 'هدف فروش این ماه');
        $this->clickAs($this->admin, 'bsend');

        $this->assertMessageSentTo($this->alice->telegram_id, 'هدف فروش این ماه');
        $this->assertMessageSentTo($this->bob->telegram_id, 'هدف فروش این ماه');
        $this->assertNothingSentTo($dave->telegram_id);
        $this->assertSame('گروه «تیم فروش»', Broadcast::sole()->audience);
    }

    public function test_the_owner_can_search_while_choosing_group_members(): void
    {
        $group = SendGroup::factory()->create();
        $this->click(self::OWNER_ID, "ge:{$group->id}:0", 'boss');

        $this->say(self::OWNER_ID, 'بهر', 'boss');

        $this->assertContains("gt:{$group->id}:0:{$this->bob->id}", array_column($this->lastButtonsSentTo(self::OWNER_ID), 'callback_data'));
    }

    public function test_a_removed_looper_in_a_group_is_skipped(): void
    {
        $group = SendGroup::factory()->create();
        $group->members()->attach([$this->alice->id, $this->bob->id]);
        $this->bob->update(['active' => false]);

        $this->compose("bg:{$group->id}", 'فقط اعضای فعال');
        $this->clickAs($this->admin, 'bsend');

        $this->assertMessageSentTo($this->alice->telegram_id, 'فقط اعضای فعال');
        $this->assertNothingSentTo($this->bob->telegram_id);
    }

    public function test_a_photo_is_copied_with_the_team_header_in_its_caption(): void
    {
        $this->sayAs($this->admin, '📢 ارسال پیام');
        $this->clickAs($this->admin, 'bt:all');
        $this->sendMessageUpdate([
            'message_id' => 77,
            'photo' => [['file_id' => 'p']],
            'caption' => 'پوستر رویداد',
            'chat' => ['id' => $this->admin->telegram_id, 'type' => 'private'],
            'from' => ['id' => $this->admin->telegram_id, 'username' => $this->admin->username, 'first_name' => 'A'],
        ]);

        $this->assertCount(1, $this->copiesTo($this->admin->telegram_id));
        $this->clickAs($this->admin, 'bsend');

        $copy = $this->copiesTo($this->alice->telegram_id)[0];
        $this->assertSame([$this->admin->telegram_id, 77], [$copy['from_chat_id'], $copy['message_id']]);
        $this->assertStringContainsString('پیام تیم لوپ', $copy['caption']);
        $this->assertStringContainsString('پوستر رویداد', $copy['caption']);
    }

    public function test_a_sticker_gets_the_header_as_a_separate_message(): void
    {
        $this->sayAs($this->admin, '📢 ارسال پیام');
        $this->clickAs($this->admin, 'bt:all');
        $this->sendMessageUpdate([
            'message_id' => 78,
            'sticker' => ['file_id' => 's'],
            'chat' => ['id' => $this->admin->telegram_id, 'type' => 'private'],
            'from' => ['id' => $this->admin->telegram_id, 'username' => $this->admin->username, 'first_name' => 'A'],
        ]);
        $this->clickAs($this->admin, 'bsend');

        $this->assertMessageSentTo($this->alice->telegram_id, 'پیام تیم لوپ');
        $this->assertArrayNotHasKey('caption', $this->copiesTo($this->alice->telegram_id)[0]->data());
    }

    public function test_a_blocked_looper_is_named_in_the_report(): void
    {
        $this->blockedChats = [$this->bob->telegram_id];

        $this->compose('bt:all', 'اطلاعیه');
        $this->clickAs($this->admin, 'bsend');

        $this->assertMessageSentTo($this->admin->telegram_id, 'احتمالاً ربات رو بلاک کردن): بهرام');
        $this->assertSame(1, Broadcast::sole()->delivered);
    }

    public function test_a_second_tap_on_send_does_not_send_twice(): void
    {
        $this->compose('bt:all', 'یک بار');

        $this->clickAs($this->admin, 'bsend');
        $this->clickAs($this->admin, 'bsend');

        $this->assertCount(1, $this->messagesSentTo($this->alice->telegram_id, 'یک بار'));
        $this->assertSame(1, Broadcast::count());
    }

    public function test_pressing_a_menu_button_abandons_the_message(): void
    {
        $this->sayAs($this->admin, '📢 ارسال پیام');
        $this->clickAs($this->admin, 'bt:all');
        $this->sayAs($this->admin, '👤 پروفایل من');
        $this->sayAs($this->admin, 'این نباید ارسال بشه');
        $this->clickAs($this->admin, 'bsend');

        $this->assertNothingSentTo($this->alice->telegram_id);
        $this->assertSame(0, Broadcast::count());
    }

    public function test_message_text_is_escaped(): void
    {
        $this->compose('bt:all', '<b>مهم</b> & فوری');
        $this->clickAs($this->admin, 'bsend');

        $this->assertMessageSentTo($this->alice->telegram_id, '&lt;b&gt;مهم&lt;/b&gt; &amp; فوری');
    }

    public function test_only_the_owner_manages_groups_and_loopers_cannot_broadcast(): void
    {
        $group = SendGroup::factory()->create();

        foreach (['gm', 'gn', "ge:{$group->id}:0", "gt:{$group->id}:0:{$this->alice->id}", "gxy:{$group->id}"] as $data) {
            $this->clickAs($this->admin, $data);
        }
        $this->assertAnswered('فقط از دست اونر');
        $this->assertNotNull($group->fresh());
        $this->assertSame(0, $group->members()->count());

        $this->sayAs($this->alice, '📢 ارسال پیام');
        foreach (['bt:all', 'bsend'] as $data) {
            $this->clickAs($this->alice, $data);
        }
        $this->assertMessageSentTo($this->alice->telegram_id, 'برای تو باز نیست');
        $this->assertAnswered('فقط از دست ادمین');
        $this->assertNothingSentTo($this->bob->telegram_id);
    }

    public function test_an_admin_demoted_before_pressing_send_cannot_send_anymore(): void
    {
        $this->compose('bt:all', 'قبل از برداشتن دسترسی');

        $this->click(self::OWNER_ID, "ady:{$this->admin->id}", 'boss');
        $this->clickAs($this->admin, 'bsend');

        $this->assertNothingSentTo($this->alice->telegram_id);
        $this->assertSame(0, Broadcast::count());
    }

    public function test_the_owner_deletes_a_group_after_confirming_and_its_loopers_stay(): void
    {
        $group = SendGroup::factory()->create();
        $group->members()->attach($this->alice->id);

        $this->click(self::OWNER_ID, "gx:{$group->id}", 'boss');
        $this->assertNotNull($group->fresh());

        $this->click(self::OWNER_ID, "gxy:{$group->id}", 'boss');

        $this->assertNull($group->fresh());
        $this->assertTrue($this->alice->fresh()->active);
    }

    public function test_group_names_must_be_unique_and_reasonable(): void
    {
        SendGroup::factory()->create(['name' => 'منتورها']);

        $this->click(self::OWNER_ID, 'gn', 'boss');
        $this->say(self::OWNER_ID, 'منتورها', 'boss');
        $this->say(self::OWNER_ID, 'م', 'boss');

        $this->assertMessageSentTo(self::OWNER_ID, 'گروهی با این اسم هست');
        $this->assertMessageSentTo(self::OWNER_ID, 'بین ۲ تا ۴۰ حرف');
        $this->assertSame(1, SendGroup::count());
    }
}
