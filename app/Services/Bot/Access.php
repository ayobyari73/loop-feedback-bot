<?php

namespace App\Services\Bot;

use App\Models\Member;

/**
 * Roles: one owner (from .env), admins chosen by the owner among loopers, and loopers.
 * The owner can do everything an admin can.
 */
class Access
{
    /**
     * @param  array<string, mixed>  $from
     */
    public function actor(array $from, int $chatId, ?Member $member): Actor
    {
        $telegramId = (int) $from['id'];
        $isOwner = $this->isOwner($telegramId);

        return new Actor($telegramId, $chatId, $from, $member, $isOwner, $isOwner || (bool) $member?->is_admin);
    }

    public function isOwner(int $telegramId): bool
    {
        $owner = (int) config('loop.owner_id');

        return $owner !== 0 && $owner === $telegramId;
    }

    public function canSeeProfile(Actor $actor, Member $subject): bool
    {
        return $actor->isAdmin || $actor->member?->id === $subject->id;
    }

    /**
     * Chats of the owner and every active admin who has started the bot.
     *
     * @return list<int>
     */
    public function staffChatIds(): array
    {
        $admins = Member::where('active', true)->where('is_admin', true)->whereNotNull('telegram_id')->pluck('telegram_id')->all();

        return array_values(array_unique(array_filter([(int) config('loop.owner_id'), ...$admins])));
    }
}
