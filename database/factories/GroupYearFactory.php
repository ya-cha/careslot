<?php

namespace Database\Factories;

use App\Models\CareGroup;
use App\Models\GroupYear;
use App\Models\KitaYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupYear>
 */
class GroupYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['group_id' => CareGroup::factory(), 'kita_year_id' => KitaYear::factory()];
    }
}
