<?php

namespace Database\Factories;

use App\Models\MailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailLog>
 */
class MailLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from' => [['address' => 'careslot@example.com', 'name' => 'careslot']],
            'to' => [['address' => fake()->safeEmail(), 'name' => fake()->name()]],
            'cc' => [],
            'bcc' => [],
            'subject' => 'Rückmeldung zur Notbetreuung',
            'html' => '<h1>Notbetreuung</h1><p>Bitte teilen Sie uns Ihren Betreuungsbedarf mit.</p>',
            'message_id' => fake()->uuid().'@example.com',
            'sent_at' => now(),
        ];
    }
}
