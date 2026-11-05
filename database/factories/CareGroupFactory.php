<?php

namespace Database\Factories;

use App\Models\CareGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CareGroup>
 */
class CareGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->unique()->word()];
    }
}
