<?php

namespace Database\Factories;

use App\Models\EmergencyAllocation;
use App\Models\LotteryDraw;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LotteryDraw> */
class LotteryDrawFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['emergency_allocation_id' => EmergencyAllocation::factory(), 'available_places' => 1, 'candidates' => [], 'winners' => [], 'losers' => [], 'drawn_at' => now()];
    }
}
