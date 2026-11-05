<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\ParentContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ParentContact> */
class ParentContactFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['family_id' => Family::factory(), 'name' => fake()->name(), 'email' => fake()->unique()->safeEmail()];
    }
}
