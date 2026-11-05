<?php

namespace Database\Factories;

use App\Models\EmailDelivery;
use App\Models\EmergencyAllocation;
use App\Models\ParentContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailDelivery> */
class EmailDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['emergency_allocation_id' => EmergencyAllocation::factory(), 'parent_contact_id' => ParentContact::factory(), 'deduplication_key' => fake()->unique()->uuid(), 'kind' => 'request', 'payload' => ['email' => 'parent@example.com', 'token' => bin2hex(random_bytes(32)), 'child_name' => 'Demo Kind', 'date' => '26.10.2026', 'deadline' => null, 'awarded' => false]];
    }
}
