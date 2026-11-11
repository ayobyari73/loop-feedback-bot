<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Telegram Bot API.
 *
 * A failed call never throws: it is logged (without the bot token) and reported through the
 * returned data, so one blocked user cannot break a whole conversation.
 */
class Telegram
{
    private const MAX_MESSAGE_LENGTH = 3800;

    /**
     * api.telegram.org is filtered in Iran: either point TELEGRAM_API_BASE at the Cloudflare Worker bridge,
     * or set TELEGRAM_PROXY (e.g. socks5h://127.0.0.1:1080).
     */
    private function http(int $timeout = 15): PendingRequest
    {
        $request = Http::timeout($timeout)->acceptJson();

        if ($proxy = config('services.telegram.proxy')) {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{field: string, contents: string, name: string}|null  $file  Sent as multipart upload.
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = [], int $timeout = 15, ?array $file = null): array
    {
        $token = config('services.telegram.token');
        $request = $this->http($timeout);

        if ($file) {
            $request = $request->attach($file['field'], $file['contents'], $file['name']);
            $params = array_map('strval', $params);
        }

        try {
            $response = $request->post(config('services.telegram.api_base')."/bot{$token}/{$method}", $params)->json() ?? [];
        } catch (ConnectionException $e) {
            Log::warning("Telegram {$method} unreachable: ".self::redact($e->getMessage()));

            return ['ok' => false, 'description' => 'connection failed'];
        }

        if (! ($response['ok'] ?? false)) {
            Log::warning("Telegram {$method} failed", ['chat_id' => $params['chat_id'] ?? null, 'response' => $response]);
        }

        return $response;
    }

    /**
     * Removes the bot token from text that may end up in logs or the console.
     */
    public static function redact(string $text): string
    {
        return preg_replace('/bot\d+:[\w-]+/', 'bot***', $text);
    }

    /**
     * Long texts are split on line breaks; the keyboard is attached to the last part.
     * Pass $menu (rows of button labels) to show the persistent bottom menu instead of inline buttons.
     * Returns whether every part reached the user.
     *
     * @param  list<list<array{text: string, callback_data: string}>>|null  $keyboard
     * @param  list<list<string>>|null  $menu
     */
    public function send(int $chatId, string $text, ?array $keyboard = null, ?array $menu = null): bool
    {
        $parts = $this->split($text);
        $allSent = true;

        foreach ($parts as $index => $part) {
            $params = ['chat_id' => $chatId, 'text' => $this->rtl($part), 'parse_mode' => 'HTML'];
            if ($index === array_key_last($parts)) {
                if ($keyboard) {
                    $params['reply_markup'] = ['inline_keyboard' => $keyboard];
                } elseif ($menu) {
                    $params['reply_markup'] = [
                        'keyboard' => array_map(fn (array $row) => array_map(fn (string $label) => ['text' => $label], $row), $menu),
                        'resize_keyboard' => true,
                        'is_persistent' => true,
                    ];
                }
            }
            $allSent = ($this->call('sendMessage', $params)['ok'] ?? false) && $allSent;
        }

        return $allSent;
    }

    /**
     * Copies a message (photo, video, file, voice...) from one chat to another without "forwarded from".
     * A caption, when given, replaces the original one.
     */
    public function copyMessage(int $chatId, int $fromChatId, int $messageId, ?string $caption = null): bool
    {
        $params = ['chat_id' => $chatId, 'from_chat_id' => $fromChatId, 'message_id' => $messageId];
        if ($caption !== null) {
            $params += ['caption' => $this->rtl($caption), 'parse_mode' => 'HTML'];
        }

        return (bool) ($this->call('copyMessage', $params)['ok'] ?? false);
    }

    /**
     * Sends a file (e.g. a dashboard or a text export). Returns whether it reached the user.
     */
    public function sendDocument(int $chatId, string $fileName, string $contents, string $caption = ''): bool
    {
        $response = $this->call('sendDocument', [
            'chat_id' => $chatId,
            'caption' => $this->rtl($caption),
            'parse_mode' => 'HTML',
        ], 60, ['field' => 'document', 'contents' => $contents, 'name' => $fileName]);

        return (bool) ($response['ok'] ?? false);
    }

    /**
     * Forces right-to-left direction on every line, so lines that start with a username or a command
     * (left-to-right characters) do not scramble the Persian text around them.
     */
    private function rtl(string $text): string
    {
        return preg_replace('/^(?=\S)/mu', "\u{200F}", $text);
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>|null  $keyboard
     */
    public function edit(int $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        $this->call('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $this->rtl($text),
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => $keyboard ?? []],
        ]);
    }

    public function answerCallback(string $id, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text]));
    }

    /**
     * @return list<string>
     */
    private function split(string $text): array
    {
        $parts = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($line) + 1 > self::MAX_MESSAGE_LENGTH) {
                $parts[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n").$line;
        }
        $parts[] = $current;

        return $parts;
    }
}
