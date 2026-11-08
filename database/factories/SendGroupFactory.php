<?php

namespace Database\Factories;

use App\Models\SendGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SendGroup>
 */
class SendGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
