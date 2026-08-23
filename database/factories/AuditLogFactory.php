<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $events = ['created', 'updated', 'deleted', 'login', 'logout', 'failed_login', 'setting_updated', 'theme_changed'];

        return [
            'user_id' => null,
            'team_id' => null,
            'event' => $this->faker->randomElement($events),
            'auditable_type' => null,
            'auditable_id' => null,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'url' => $this->faker->url(),
            'method' => $this->faker->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
            'old_values' => null,
            'new_values' => null,
            'description' => $this->faker->sentence(),
        ];
    }
}
