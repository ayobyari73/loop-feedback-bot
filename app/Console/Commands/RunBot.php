<?php

namespace App\Console\Commands;

use App\Services\Telegram;
use App\Services\UpdatePoller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:run-bot')]
#[Description('Run the Loop feedback bot using long polling (no webhook or public URL needed)')]
class RunBot extends Command
{
    /**
     * Poll Telegram for updates until stopped with Ctrl+C.
     */
    public function handle(Telegram $telegram, UpdatePoller $poller): int
    {
        if (! config('services.telegram.token')) {
            $this->error('TELEGRAM_BOT_TOKEN is not set in .env');

            return self::FAILURE;
        }

        $me = $telegram->call('getMe');
        if (! ($me['ok'] ?? false)) {
            $this->error('Cannot reach Telegram at '.config('services.telegram.api_base').' (bad token, or the address is blocked: set TELEGRAM_API_BASE to the Cloudflare bridge, or TELEGRAM_PROXY).');

            return self::FAILURE;
        }

        if ($telegram->call('getWebhookInfo')['result']['url'] ?? '') {
            $telegram->call('deleteWebhook');
            $this->warn('A webhook was set; it has been removed so long polling can run (use `app:webhook set` to go back).');
        }

        $this->info("Bot @{$me['result']['username']} is running. Press Ctrl+C to stop.");

        while (true) {
            try {
                if (! $poller->poll()) {
                    $this->warn('Cannot reach Telegram, retrying in 5 seconds...');
                    sleep(5);
                }
            } catch (Throwable $e) {
                $this->warn(Telegram::redact($e->getMessage()));
                sleep(3);
            }
        }
    }
}
