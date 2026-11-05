<?php

namespace Database\Factories;

use App\Models\Child;
use App\Models\EmergencyAllocation;
use App\Models\WaitingListMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WaitingListMovement> */
class WaitingListMovementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['emergency_allocation_id' => EmergencyAllocation::factory(), 'child_id' => Child::factory(), 'original_position' => 1, 'new_position' => 2, 'reason' => 'Nachrückplatz in Anspruch genommen.'];
    }
}
