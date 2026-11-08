<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Feedback extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const NEGATIVE = 'negative';

    /** @var array<string, string> The four indicators of a looper's profile. */
    public const KINDS = [
        'network' => '🌐 انتقال شبکه',
        'knowledge' => '📚 انتقال دانش',
        'experience' => '🧭 انتقال تجربه',
        self::NEGATIVE => '⚠️ فیدبک منفی',
    ];

    /** @var array<string, string> */
    public const SHORT_KINDS = [
        'network' => '🌐 شبکه',
        'knowledge' => '📚 دانش',
        'experience' => '🧭 تجربه',
        self::NEGATIVE => '⚠️ منفی',
    ];

    protected $table = 'feedback';

    protected $guarded = [];

    protected $casts = ['delivered_at' => 'datetime'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'from_member_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'to_member_id');
    }

    public function isNegative(): bool
    {
        return $this->kind === self::NEGATIVE;
    }
}
