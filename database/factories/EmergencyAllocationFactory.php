<?php

namespace Database\Factories;

use App\Models\EmergencyAllocation;
use App\Models\EmergencyDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmergencyAllocation> */
class EmergencyAllocationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['emergency_day_id' => EmergencyDay::factory(), 'capacity' => 2, 'status' => 'responses'];
    }
}
