<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * How many points the giver and the receiver of one feedback of a kind get. Set by the owner.
 * Points are always computed from the current rules, so a change applies to past feedback too.
 */
class ScoreRule extends Model
{
    use HasFactory;

    public const MIN_POINTS = -10;

    public const MAX_POINTS = 10;

    protected $guarded = [];

    protected $casts = ['giver_points' => 'integer', 'receiver_points' => 'integer'];

    /**
     * @return array<string, array{giver: int, receiver: int}>
     */
    public static function table(): array
    {
        $rules = array_fill_keys(array_keys(Feedback::KINDS), ['giver' => 0, 'receiver' => 0]);

        foreach (self::all() as $rule) {
            $rules[$rule->kind] = ['giver' => $rule->giver_points, 'receiver' => $rule->receiver_points];
        }

        return $rules;
    }
}
