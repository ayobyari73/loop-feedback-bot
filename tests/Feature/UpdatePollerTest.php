<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Services\UpdatePoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class UpdatePollerTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.token' => 'fake', 'loop.owner_id' => 900]);
        Cache::forget(UpdatePoller::OFFSET_KEY);
        $this->member = Member::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function meUpdate(int $updateId): array
    {
        return ['update_id' => $updateId, 'message' => [
            'text' => '/me',
            'chat' => ['id' => $this->member->telegram_id, 'type' => 'private'],
            'from' => ['id' => $this->member->telegram_id, 'username' => $this->member->username, 'first_name' => 'T'],
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  ...$rounds  The updates each getUpdates call returns, in order.
     */
    private function fakeTelegram(array ...$rounds): void
    {
        $sequence = Http::sequence();
        foreach ($rounds as $updates) {
            $sequence->push(['ok' => true, 'result' => $updates]);
        }
        $sequence->whenEmpty(Http::response(['ok' => true, 'result' => []]));

        Http::fake([
            'api.telegram.org/botfake/getUpdates' => $sequence,
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function meRepliesSent(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === $this->member->telegram_id)->count();
    }

    public function test_updates_are_handled_and_the_next_offset_is_stored(): void
    {
        $this->fakeTelegram([$this->meUpdate(10), $this->meUpdate(11)], []);
        $poller = app(UpdatePoller::class);

        $poller->poll(0);
        $poller->poll(0);

        $this->assertSame(12, Cache::get(UpdatePoller::OFFSET_KEY));
        $this->assertSame(2, $this->meRepliesSent());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'getUpdates') && $request['offset'] === 12);
    }

    public function test_a_restarted_bot_continues_from_the_stored_offset_and_replays_nothing(): void
    {
        Cache::forever(UpdatePoller::OFFSET_KEY, 40);
        $this->fakeTelegram([]);

        app(UpdatePoller::class)->poll(0);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'getUpdates') && $request['offset'] === 40);
        $this->assertSame(0, $this->meRepliesSent());
    }

    public function test_an_update_that_crashes_is_logged_and_skipped_without_blocking_the_next_ones(): void
    {
        Log::spy();
        $broken = ['update_id' => 20, 'callback_query' => ['data' => 'x']];
        $this->fakeTelegram([$broken, $this->meUpdate(21)]);

        $this->assertTrue(app(UpdatePoller::class)->poll(0));

        $this->assertSame(22, Cache::get(UpdatePoller::OFFSET_KEY));
        $this->assertSame(1, $this->meRepliesSent());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_an_unreachable_telegram_returns_false_and_keeps_the_offset(): void
    {
        Cache::forever(UpdatePoller::OFFSET_KEY, 5);
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertFalse(app(UpdatePoller::class)->poll(0));
        $this->assertSame(5, Cache::get(UpdatePoller::OFFSET_KEY));
    }

    public function test_a_telegram_api_error_such_as_a_second_running_bot_returns_false(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 409, 'description' => 'Conflict: terminated by other getUpdates request'], 409)]);

        $this->assertFalse(app(UpdatePoller::class)->poll(0));
    }
}
