<?php

namespace Database\Factories;

use App\Models\AllocationResult;
use App\Models\Child;
use App\Models\EmergencyAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AllocationResult> */
class AllocationResultFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['emergency_allocation_id' => EmergencyAllocation::factory(), 'child_id' => Child::factory(), 'phase' => null, 'awarded' => false, 'offered' => false, 'takes_place' => false, 'reason' => 'Kein Bedarf.', 'snapshot' => []];
    }
}
