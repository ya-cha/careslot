<?php

namespace Database\Factories;

use App\Models\CareGroup;
use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Family> */
class FamilyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['group_id' => CareGroup::factory(), 'name' => fake()->lastName()];
    }
}
