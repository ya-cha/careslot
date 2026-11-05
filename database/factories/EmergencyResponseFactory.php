<?php

namespace Database\Factories;

use App\Models\Child;
use App\Models\EmergencyAllocation;
use App\Models\EmergencyDay;
use App\Models\EmergencyResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmergencyResponse> */
class EmergencyResponseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'child_id' => Child::factory(),
            'emergency_allocation_id' => function (array $attributes): int {
                $child = Child::whereKey($attributes['child_id'])->firstOrFail();
                $day = EmergencyDay::factory()->for($child->kitaYear)->for($child->group, 'group')->create();

                return EmergencyAllocation::factory()->for($day, 'emergencyDay')->create()->id;
            },
            'claim_snapshot' => ['status' => 'none', 'rules' => [], 'explanation' => 'Kein Anspruch.'],
        ];
    }
}
