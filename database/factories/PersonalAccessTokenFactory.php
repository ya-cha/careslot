<?php

namespace Database\Factories;

use App\Models\EmergencyResponse;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PersonalAccessToken> */
class PersonalAccessTokenFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['emergency_response_id' => EmergencyResponse::factory(), 'parent_contact_id' => ParentContact::factory(), 'token_hash' => hash('sha256', bin2hex(random_bytes(32)))];
    }
}
