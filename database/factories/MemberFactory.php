<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_id' => fake()->unique()->numberBetween(1000, 999999999),
            'username' => fake()->unique()->lexify('looper_?????'),
            'name' => fake()->name(),
            'active' => true,
        ];
    }

    /**
     * A looper added by the admin who has not opened the bot yet.
     */
    public function notStarted(): static
    {
        return $this->state(['telegram_id' => null]);
    }
}
