<?php

namespace Tests\Feature;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TalksToTheBot;
use Tests\TestCase;

class LargeCommunityTest extends TestCase
{
    use RefreshDatabase;
    use TalksToTheBot;

    private Member $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
        $this->alice = Member::factory()->create(['name' => 'آلیس']);
        Member::factory()->count(199)->create();
    }

    /**
     * @return list<string>
     */
    private function pickButtons(): array
    {
        return array_values(array_filter(
            array_column($this->lastButtonsSentTo($this->alice->telegram_id), 'callback_data'),
            fn (string $data) => str_starts_with($data, 'to:')
        ));
    }

    public function test_the_first_page_shows_eight_loopers_with_a_next_button(): void
    {
        $this->say($this->alice->telegram_id, '/feedback', $this->alice->username);

        $data = array_column($this->lastButtonsSentTo($this->alice->telegram_id), 'callback_data');
        $this->assertCount(8, $this->pickButtons());
        $this->assertContains('p:1', $data);
        $this->assertNotContains('p:-1', $data);
    }

    public function test_the_last_page_has_the_remaining_loopers_and_no_next_button(): void
    {
        $this->say($this->alice->telegram_id, '/feedback', $this->alice->username);

        $this->click($this->alice->telegram_id, 'p:999', $this->alice->username);

        $edit = Http::recorded()->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), 'editMessageText'))->last();
        $data = array_column(array_merge(...$edit['reply_markup']['inline_keyboard']), 'callback_data');
        $this->assertCount(7, array_filter($data, fn (string $item) => str_starts_with($item, 'to:')));
        $this->assertContains('p:23', $data);
        $this->assertNotContains('p:25', $data);
    }

    public function test_the_list_renders_with_a_small_constant_number_of_queries(): void
    {
        DB::enableQueryLog();

        $this->say($this->alice->telegram_id, '/feedback', $this->alice->username);

        $this->assertLessThan(15, count(DB::getQueryLog()));
    }

    public function test_typing_part_of_a_name_finds_the_looper_even_with_arabic_letters(): void
    {
        $target = Member::factory()->create(['name' => 'سارا احمدی']);
        $this->say($this->alice->telegram_id, '/feedback', $this->alice->username);

        $this->say($this->alice->telegram_id, 'احمدي', $this->alice->username);

        $this->assertSame(["to:{$target->id}"], $this->pickButtons());
    }

    public function test_search_by_username_works(): void
    {
        $target = Member::factory()->create(['username' => 'zeynab_codes']);
        $this->say($this->alice->telegram_id, '/feedback', $this->alice->username);

        $this->say($this->alice->telegram_id, 'ZEYNAB', $this->alice->username);

        $this->assertSame(["to:{$target->id}"], $this->pickButtons());
    }

    public function test_search_with_too_many_results_shows_only_the_first_page_and_asks_for_more_detail(): void
    {
        Member::factory()->count(20)->sequence(fn ($sequence) => ['name' => 'تست '.$sequence->index])->create();
        $this->say($this->alice->telegram_id, '/feedback', $this->alice->username);

        $this->say($this->alice->telegram_id, 'تست', $this->alice->username);

        $this->assertCount(8, $this->pickButtons());
        $this->assertMessageSentTo($this->alice->telegram_id, 'نتیجه‌ها زیاده');
    }

    public function test_search_without_results_says_so_and_keeps_waiting(): void
    {
        $this->say($this->alice->telegram_id, '/feedback', $this->alice->username);

        $this->say($this->alice->telegram_id, 'چنین نامی وجود ندارد', $this->alice->username);

        $this->assertMessageSentTo($this->alice->telegram_id, 'پیدا نشد');
        $this->assertSame('choose_target', $this->alice->fresh()->state);
    }

    public function test_the_chat_summary_stays_short_and_points_to_the_dashboard_for_everyone_else(): void
    {
        $this->say(self::OWNER_ID, '/season', 'boss');

        $messages = $this->messagesSentTo(self::OWNER_ID);
        $this->assertCount(1, $messages);
        $this->assertLessThanOrEqual(4096, mb_strlen($messages[0]['text']));
        $this->assertStringContainsString('و 190 نفر دیگه', $messages[0]['text']);
    }

    public function test_the_dashboard_for_two_hundred_loopers_is_built_quickly(): void
    {
        $started = microtime(true);

        $this->say(self::OWNER_ID, '/season', 'boss');

        $this->assertLessThan(5, microtime(true) - $started);
    }
}
