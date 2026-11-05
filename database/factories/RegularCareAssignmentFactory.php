<?php

namespace Database\Factories;

use App\Models\Child;
use App\Models\RegularCareAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegularCareAssignment> */
class RegularCareAssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['child_id' => Child::factory(), 'weekday' => 1, 'pattern' => 'always', 'valid_from' => '2026-08-01'];
    }
}
