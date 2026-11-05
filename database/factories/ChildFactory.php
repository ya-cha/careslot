<?php

namespace Database\Factories;

use App\Models\CareGroup;
use App\Models\Child;
use App\Models\Family;
use App\Models\KitaYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Child> */
class ChildFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['group_id' => CareGroup::factory(), 'kita_year_id' => KitaYear::factory(), 'family_id' => fn (array $attributes): int => Family::factory()->create(['group_id' => $attributes['group_id']])->id, 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'active' => true];
    }
}
