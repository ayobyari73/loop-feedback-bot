<?php

namespace App\Http\Controllers;

use App\Services\FeedbackBot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Receives Telegram updates in webhook mode (the alternative to `app:run-bot` long polling).
 *
 * Only requests carrying the configured secret token are accepted. Each update is handled once (Telegram
 * re-sends an update when it does not get a quick 200) and after the response is sent, so a slow
 * broadcast never makes Telegram time out and retry.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, FeedbackBot $bot): Response
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $update = $request->json()->all();
        $updateId = $update['update_id'] ?? null;

        if (is_int($updateId) && Cache::add("loop-bot:update:{$updateId}", true, now()->addDay())) {
            dispatch(fn () => $bot->handle($update))->afterResponse();
        }

        return response('', 200);
    }
}
