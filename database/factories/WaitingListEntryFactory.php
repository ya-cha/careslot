<?php

namespace Database\Factories;

use App\Models\Child;
use App\Models\WaitingListEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WaitingListEntry> */
class WaitingListEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'child_id' => Child::factory(),
            'group_id' => fn (array $attributes): int => Child::whereKey($attributes['child_id'])->firstOrFail()->group_id,
            'kita_year_id' => fn (array $attributes): int => Child::whereKey($attributes['child_id'])->firstOrFail()->kita_year_id,
            'position' => 1,
        ];
    }
}
