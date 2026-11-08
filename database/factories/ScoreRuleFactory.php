<?php

namespace Database\Factories;

use App\Models\ScoreRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScoreRule>
 */
class ScoreRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => fake()->unique()->word(),
            'giver_points' => 1,
            'receiver_points' => 1,
        ];
    }
}
