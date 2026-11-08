<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\ScoreRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TalksToTheBot;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
    }

    /**
     * @return list<string>
     */
    private function menuSentTo(int $chatId): array
    {
        $request = collect($this->messagesSentTo($chatId))->last(fn (Request $request) => isset($request['reply_markup']['keyboard']));

        return array_column(array_merge(...$request['reply_markup']['keyboard']), 'text');
    }

    public function test_each_role_gets_its_own_menu(): void
    {
        $looper = Member::factory()->create();
        $admin = $this->admin();

        $this->sayAs($looper, '/start');
        $this->sayAs($admin, '/start');
        $this->say(self::OWNER_ID, '/start', 'boss');

        $this->assertSame(['📝 ثبت فیدبک', '👤 پروفایل من'], $this->menuSentTo($looper->telegram_id));
        $this->assertSame(['📝 ثبت فیدبک', '👤 پروفایل من', '➕ افزودن لوپر', '👥 لوپرها', '📊 گزارش‌ها', '📢 ارسال پیام'], $this->menuSentTo($admin->telegram_id));
        $this->assertContains('👮 ادمین‌ها', $this->menuSentTo(self::OWNER_ID));
        $this->assertContains('⚙️ امتیازدهی', $this->menuSentTo(self::OWNER_ID));
        $this->assertContains('📊 گزارش‌ها', $this->menuSentTo(self::OWNER_ID));
    }

    public function test_the_owner_makes_a_looper_admin_and_the_looper_is_told_with_a_new_menu(): void
    {
        $looper = Member::factory()->create();

        $this->say(self::OWNER_ID, '👮 ادمین‌ها', 'boss');
        $this->click(self::OWNER_ID, 'ada:0', 'boss');
        $this->click(self::OWNER_ID, "adp:{$looper->id}", 'boss');

        $this->assertTrue($looper->fresh()->is_admin);
        $this->assertMessageSentTo($looper->telegram_id, 'تو رو ادمین کرد');
        $this->assertContains('👥 لوپرها', $this->menuSentTo($looper->telegram_id));
    }

    public function test_the_owner_removes_an_admin_after_confirming_and_they_stay_a_looper(): void
    {
        $admin = $this->admin();

        $this->click(self::OWNER_ID, "adr:{$admin->id}", 'boss');
        $this->assertTrue($admin->fresh()->is_admin);

        $this->click(self::OWNER_ID, "ady:{$admin->id}", 'boss');

        $admin->refresh();
        $this->assertFalse($admin->is_admin);
        $this->assertTrue($admin->active);
        $this->assertSame(['📝 ثبت فیدبک', '👤 پروفایل من'], $this->menuSentTo($admin->telegram_id));
    }

    public function test_an_admin_cannot_appoint_admins_or_change_scoring(): void
    {
        $admin = $this->admin();
        $looper = Member::factory()->create();

        $this->sayAs($admin, '👮 ادمین‌ها');
        $this->sayAs($admin, '⚙️ امتیازدهی');
        foreach (['adm', 'ada:0', "adp:{$looper->id}", "ady:{$admin->id}", 'sc', 'se:network:g'] as $data) {
            $this->clickAs($admin, $data);
        }
        $this->sayAs($admin, '5');

        $this->assertFalse($looper->fresh()->is_admin);
        $this->assertTrue($admin->fresh()->is_admin);
        $this->assertSame(1, ScoreRule::where('kind', 'network')->value('giver_points'));
        $this->assertCount(2, $this->messagesSentTo($admin->telegram_id, 'برای تو باز نیست'));
        $this->assertAnswered('فقط از دست اونر');
    }

    public function test_an_admin_can_do_admin_work(): void
    {
        $admin = $this->admin();

        $this->sayAs($admin, '/addlooper @new_looper نفر جدید');
        $this->sayAs($admin, '📊 گزارش‌ها');

        $this->assertNotNull(Member::where('username', 'new_looper')->first());
        $this->assertMessageSentTo($admin->telegram_id, 'کدوم گزارش');
    }

    public function test_a_removed_admin_loses_admin_rights_with_the_membership(): void
    {
        $admin = $this->admin();

        $this->click(self::OWNER_ID, "rmy:{$admin->id}", 'boss');
        $this->sayAs($admin, '/addlooper @sneaky_one');

        $this->assertFalse($admin->fresh()->is_admin);
        $this->assertNull(Member::where('username', 'sneaky_one')->first());
    }

    public function test_without_a_configured_owner_nobody_is_owner(): void
    {
        config(['loop.owner_id' => 0]);

        $this->say(0, '⚙️ امتیازدهی', 'nobody');

        Http::assertNotSent(fn (Request $request) => str_contains($request['text'] ?? '', 'سیستم امتیازدهی'));
    }
}
