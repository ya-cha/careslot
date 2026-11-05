<?php

namespace Database\Factories;

use App\Models\CareGroup;
use App\Models\EmergencyDay;
use App\Models\KitaYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmergencyDay> */
class EmergencyDayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['group_id' => CareGroup::factory(), 'kita_year_id' => KitaYear::factory(), 'date' => '2026-10-26', 'capacity' => 2, 'starts_at' => '08:00', 'ends_at' => '16:00'];
    }
}
