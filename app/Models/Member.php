<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    use HasFactory;

    /** A conversation left unfinished for this long is dropped, so a later casual message never becomes feedback. */
    public const CONVERSATION_MINUTES = 30;

    protected $guarded = [];

    protected $casts = [
        'draft' => 'array',
        'active' => 'boolean',
        'is_admin' => 'boolean',
        'state_updated_at' => 'datetime',
    ];

    public function received(): HasMany
    {
        return $this->hasMany(Feedback::class, 'to_member_id');
    }

    public function given(): HasMany
    {
        return $this->hasMany(Feedback::class, 'from_member_id');
    }

    public function label(): string
    {
        return e($this->name ?: $this->handle());
    }

    /**
     * The name, or the right-to-left-safe @handle when there is no name yet. Not HTML-escaped.
     */
    public function displayName(): string
    {
        return $this->name ?: $this->handle();
    }

    /**
     * The @username wrapped in left-to-right marks, so the @ stays in front of the name
     * instead of jumping to the other side when the handle sits inside Persian text.
     */
    public function handle(): string
    {
        return "\u{200E}@{$this->username}\u{200E}";
    }

    /**
     * One line for admin lists: "Name — @handle", or just the handle when the looper has no name yet.
     */
    public function listing(): string
    {
        return $this->name ? e($this->name)." — <code>@{$this->username}</code>" : "<code>@{$this->username}</code>";
    }

    /**
     * @param  array<string, mixed>|null  $draft
     */
    public function moveTo(string $state, ?array $draft = null): void
    {
        $this->update(['state' => $state, 'draft' => $draft, 'state_updated_at' => now()]);
    }

    public function resetState(): void
    {
        if ($this->state !== 'idle' || $this->draft !== null) {
            $this->update(['state' => 'idle', 'draft' => null, 'state_updated_at' => null]);
        }
    }

    public function conversationExpired(): bool
    {
        return $this->state !== 'idle'
            && ($this->state_updated_at === null || $this->state_updated_at->lt(now()->subMinutes(self::CONVERSATION_MINUTES)));
    }
}
