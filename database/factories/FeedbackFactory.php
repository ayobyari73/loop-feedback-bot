<?php

namespace Database\Factories;

use App\Models\Feedback;
use App\Models\Member;
use App\Services\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_member_id' => Member::factory(),
            'to_member_id' => Member::factory(),
            'kind' => fake()->randomElement(['network', 'knowledge', 'experience']),
            'description' => fake()->sentence(),
            'season' => Season::number(),
        ];
    }

    public function negative(): static
    {
        return $this->state(['kind' => Feedback::NEGATIVE]);
    }
}
