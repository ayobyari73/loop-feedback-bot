<?php

namespace App\Console\Commands;

use App\Services\Telegram;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:webhook {action=info : set, delete or info} {--url= : Public HTTPS address of the bot (default: APP_URL)} {--drop-pending : Discard updates that arrived while the bot was down}')]
#[Description('Switch the bot to webhook mode (Telegram pushes updates to /telegram/webhook) or back')]
class TelegramWebhook extends Command
{
    /**
     * Registers, removes or shows the webhook at Telegram.
     */
    public function handle(Telegram $telegram): int
    {
        return match ($this->argument('action')) {
            'set' => $this->set($telegram),
            'delete' => $this->report($telegram->call('deleteWebhook', ['drop_pending_updates' => (bool) $this->option('drop-pending')]), 'Webhook removed; use `php artisan app:run-bot` for long polling.'),
            default => $this->showInfo($telegram),
        };
    }

    private function set(Telegram $telegram): int
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if (! preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
            $this->error('Set TELEGRAM_WEBHOOK_SECRET in .env first: 16+ characters of A-Z, a-z, 0-9, _ or - (e.g. `openssl rand -hex 32`).');

            return self::FAILURE;
        }

        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/').'/telegram/webhook';
        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram only calls HTTPS addresses; got {$url}. Set APP_URL or pass --url.");

            return self::FAILURE;
        }

        return $this->report($telegram->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'max_connections' => 10,
            'drop_pending_updates' => (bool) $this->option('drop-pending'),
        ]), "Webhook set to {$url}");
    }

    private function showInfo(Telegram $telegram): int
    {
        $info = $telegram->call('getWebhookInfo')['result'] ?? null;
        if ($info === null) {
            $this->error('Cannot reach Telegram.');

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['url', $info['url'] ?: '(none — long polling mode)'],
            ['pending updates', $info['pending_update_count'] ?? 0],
            ['last error', isset($info['last_error_date']) ? date('Y-m-d H:i', $info['last_error_date']).' '.($info['last_error_message'] ?? '') : '-'],
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function report(array $response, string $success): int
    {
        if ($response['ok'] ?? false) {
            $this->info($success);

            return self::SUCCESS;
        }

        $this->error('Telegram refused: '.($response['description'] ?? 'no response (is the bridge / TELEGRAM_API_BASE reachable?)'));

        return self::FAILURE;
    }
}
