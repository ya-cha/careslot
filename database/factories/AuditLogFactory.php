<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Child;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['event' => 'test.event', 'subject_type' => Child::class, 'subject_id' => Child::factory(), 'data' => []];
    }
}
