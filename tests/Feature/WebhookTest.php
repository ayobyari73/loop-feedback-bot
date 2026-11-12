<?php

namespace Tests\Feature;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-0123456789abcdef';

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.token' => '123:fake',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.api_base' => 'https://bridge.example.org',
            'loop.owner_id' => 900,
            'cache.default' => 'database',
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
        $this->member = Member::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function update(int $id): array
    {
        return ['update_id' => $id, 'message' => [
            'message_id' => 1,
            'text' => '/me',
            'chat' => ['id' => $this->member->telegram_id, 'type' => 'private'],
            'from' => ['id' => $this->member->telegram_id, 'username' => $this->member->username, 'first_name' => 'T'],
        ]];
    }

    private function repliesToMember(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === $this->member->telegram_id)->count();
    }

    public function test_an_update_with_the_right_secret_is_handled_and_replies_go_through_the_bridge(): void
    {
        $this->postJson('/telegram/webhook', $this->update(1), ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])
            ->assertOk();

        $this->assertSame(1, $this->repliesToMember());
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://bridge.example.org/bot123:fake/'));
    }

    public function test_the_webhook_route_has_no_csrf_session_or_cookie_middleware(): void
    {
        $middleware = Route::getRoutes()->getByName('telegram.webhook')->gatherMiddleware();

        $this->assertSame([], $middleware, 'Telegram sends no cookies or CSRF token; the secret header is the only check.');
    }

    public function test_a_request_without_or_with_a_wrong_secret_is_rejected(): void
    {
        $this->postJson('/telegram/webhook', $this->update(1))->assertForbidden();
        $this->postJson('/telegram/webhook', $this->update(2), ['X-Telegram-Bot-Api-Secret-Token' => 'guess'])->assertForbidden();

        $this->assertSame(0, $this->repliesToMember());
    }

    public function test_the_webhook_is_closed_when_no_secret_is_configured(): void
    {
        config(['services.telegram.webhook_secret' => null]);

        $this->postJson('/telegram/webhook', $this->update(1), ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertForbidden();
    }

    public function test_an_update_resent_by_telegram_is_handled_only_once(): void
    {
        Bus::fake();
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET];

        $this->postJson('/telegram/webhook', $this->update(7), $headers)->assertOk();
        $this->postJson('/telegram/webhook', $this->update(7), $headers)->assertOk();
        $this->postJson('/telegram/webhook', $this->update(8), $headers)->assertOk();

        Bus::assertDispatchedAfterResponseTimes(CallQueuedClosure::class, 2);
    }

    public function test_setting_the_webhook_registers_the_https_address_and_the_secret(): void
    {
        config(['app.url' => 'https://bot.getinloop.ir']);

        $this->artisan('app:webhook set')->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://bot.getinloop.ir/telegram/webhook'
            && $request['secret_token'] === self::SECRET
            && $request['allowed_updates'] === ['message', 'callback_query']);
    }

    public function test_setting_the_webhook_refuses_a_weak_secret_or_plain_http(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->artisan('app:webhook set')->assertFailed();

        config(['app.url' => 'https://bot.getinloop.ir', 'services.telegram.webhook_secret' => 'short']);
        $this->artisan('app:webhook set')->assertFailed();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'setWebhook'));
    }
}
