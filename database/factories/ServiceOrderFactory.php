<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceOrder>
 */
class ServiceOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_number' => 'ADC-'.now()->format('ym').'-'.fake()->unique()->numerify('####'),
            'tracking_token_hash' => hash('sha256', Str::random(64)),
            'access_code_hash' => hash('sha256', Str::upper(Str::random(8))),
            'service_id' => Service::factory(),
            'customer_name' => fake()->name(),
            'phone' => '628'.fake()->numerify('##########'),
            'email' => fake()->optional()->safeEmail(),
            'address' => fake()->streetAddress(),
            'district' => fake()->city(),
            'service_mode' => 'onsite',
            'complaint' => fake()->paragraph(),
            'preferred_date' => now()->addDays(2)->toDateString(),
            'preferred_window' => 'morning',
            'urgency' => 'normal',
            'risk_level' => 'unassessed',
            'status' => 'new',
        ];
    }
}
