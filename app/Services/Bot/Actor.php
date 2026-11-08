<?php

namespace App\Services\Bot;

use App\Models\Member;

/**
 * Who sent the current update and what they are allowed to do.
 */
final class Actor
{
    /**
     * @param  array<string, mixed>  $from  The raw Telegram "from" object.
     */
    public function __construct(
        public readonly int $telegramId,
        public readonly int $chatId,
        public readonly array $from,
        public readonly ?Member $member,
        public readonly bool $isOwner,
        public readonly bool $isAdmin,
    ) {}
}
