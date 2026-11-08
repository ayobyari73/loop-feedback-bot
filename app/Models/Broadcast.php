<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A record of one message sent by the owner or an admin to one looper, a group or everyone.
 */
class Broadcast extends Model
{
    protected $guarded = [];
}
