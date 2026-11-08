<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches Telegram updates with long polling and hands them to the bot.
 *
 * The next offset is stored before an update is handled, so a restart (or a crash) never replays an
 * update that was already seen: at worst one message is lost, never handled twice. An update that
 * throws is logged and skipped instead of blocking everything behind it.
 */
class UpdatePoller
{
    public const OFFSET_KEY = 'loop-bot:offset';

    public function __construct(private Telegram $telegram, private FeedbackBot $bot) {}

    /**
     * Runs one polling round. Returns false when Telegram could not be reached.
     */
    public function poll(int $longPollSeconds = 25): bool
    {
        $response = $this->telegram->call('getUpdates', [
            'offset' => (int) Cache::get(self::OFFSET_KEY, 0),
            'timeout' => $longPollSeconds,
        ], $longPollSeconds + 10);

        if (! ($response['ok'] ?? false)) {
            return false;
        }

        foreach ($response['result'] ?? [] as $update) {
            Cache::forever(self::OFFSET_KEY, $update['update_id'] + 1);

            try {
                $this->bot->handle($update);
            } catch (Throwable $e) {
                Log::error('Bot failed on update '.$update['update_id'].': '.Telegram::redact($e->getMessage()), ['exception' => $e]);
            }
        }

        return true;
    }
}
