<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A saved list of loopers the owner and admins can message in one go.
 */
class SendGroup extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'send_group_member');
    }
}
