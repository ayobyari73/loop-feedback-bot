<?php

namespace Tests\Concerns;

use App\Models\Feedback;
use App\Models\Member;
use App\Services\FeedbackBot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Drives the bot with fake Telegram updates and a fake Telegram server that can block chats or go offline.
 */
trait TalksToTheBot
{
    private const OWNER_ID = 900;

    private FeedbackBot $bot;

    private int $updateId = 0;

    /** @var list<int> Chats for which Telegram answers "403 bot was blocked by the user". */
    private array $blockedChats = [];

    private bool $telegramOffline = false;

    protected function setUpBot(): void
    {
        config(['services.telegram.token' => 'fake', 'loop.owner_id' => self::OWNER_ID]);

        Http::fake(function (Request $request) {
            if ($this->telegramOffline) {
                throw new ConnectionException("cURL error 28: Operation timed out for {$request->url()}");
            }

            $blocked = in_array($request['chat_id'] ?? null, $this->blockedChats, true);

            return Http::response(
                $blocked
                    ? ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user']
                    : ['ok' => true, 'result' => []],
                $blocked ? 403 : 200,
            );
        });

        $this->bot = app(FeedbackBot::class);
    }

    private function admin(array $attributes = []): Member
    {
        return Member::factory()->create(['is_admin' => true, ...$attributes]);
    }

    private function say(int $id, string $text, ?string $username = null): void
    {
        $this->sendMessageUpdate(['text' => $text, 'chat' => ['id' => $id, 'type' => 'private'], 'from' => ['id' => $id, 'username' => $username, 'first_name' => 'Tester']]);
    }

    private function sayAs(Member $member, string $text): void
    {
        $this->say($member->telegram_id, $text, $member->username);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function sendMessageUpdate(array $message): void
    {
        $this->bot->handle(['update_id' => ++$this->updateId, 'message' => $message]);
    }

    private function click(int $id, string $data, ?string $username = null): void
    {
        $this->bot->handle(['update_id' => ++$this->updateId, 'callback_query' => [
            'id' => 'cb',
            'data' => $data,
            'from' => ['id' => $id, 'username' => $username, 'first_name' => 'Tester'],
            'message' => ['message_id' => 5, 'chat' => ['id' => $id, 'type' => 'private']],
        ]]);
    }

    private function clickAs(Member $member, string $data): void
    {
        $this->click($member->telegram_id, $data, $member->username);
    }

    /**
     * Walks a member through the whole feedback conversation, preview included.
     */
    private function submitFeedback(Member $from, Member $to, string $kind, string $description): Feedback
    {
        $this->sayAs($from, '/feedback');
        $this->clickAs($from, "to:{$to->id}");
        $this->clickAs($from, "k:{$kind}");
        $this->sayAs($from, $description);
        $this->clickAs($from, 'ok');

        return Feedback::latest('id')->firstOrFail();
    }

    /**
     * Matches sent messages and edits of existing messages.
     */
    private function assertMessageSentTo(int $chatId, string $needle): void
    {
        Http::assertSent(fn (Request $request) => (str_contains($request->url(), 'sendMessage') || str_contains($request->url(), 'editMessageText'))
            && $request['chat_id'] === $chatId
            && str_contains($request['text'], $needle));
    }

    private function assertNothingSentTo(int $chatId): void
    {
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'sendMessage') && $request['chat_id'] === $chatId);
    }

    /**
     * @return list<Request>
     */
    private function messagesSentTo(int $chatId, string $needle = '', bool $includeEdits = false): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => (str_contains($request->url(), 'sendMessage') || ($includeEdits && str_contains($request->url(), 'editMessageText')))
                && $request['chat_id'] === $chatId
                && str_contains($request['text'], $needle))
            ->values()->all();
    }

    /**
     * The inline buttons of the last message sent or edited in a chat, flattened.
     *
     * @return list<array{text: string, callback_data: string}>
     */
    private function lastButtonsSentTo(int $chatId): array
    {
        $last = collect($this->messagesSentTo($chatId, '', true))->last();

        return array_merge(...($last['reply_markup']['inline_keyboard'] ?? [[]]));
    }

    private function lastTextSentTo(int $chatId): string
    {
        return collect($this->messagesSentTo($chatId, '', true))->last()['text'] ?? '';
    }

    private function assertAnswered(string $needle): void
    {
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'answerCallbackQuery')
            && str_contains($request['text'] ?? '', $needle));
    }
}
