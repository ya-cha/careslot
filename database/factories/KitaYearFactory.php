<?php

namespace Database\Factories;

use App\Models\KitaYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KitaYear> */
class KitaYearFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => '2026/2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-07-31', 'status' => 'active'];
    }
}
