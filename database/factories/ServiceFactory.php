<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('???.##')),
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'summary' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'starting_price' => fake()->numberBetween(0, 500000),
            'pricing_label' => 'Mulai Rp'.fake()->numberBetween(50, 500).' ribu',
            'service_mode' => fake()->randomElement(['onsite', 'dropoff', 'remote']),
            'risk_level' => fake()->randomElement(['low', 'medium', 'high']),
            'icon' => 'wrench',
            'warranty_days' => 14,
            'outcomes' => [fake()->sentence(3)],
            'is_active' => true,
            'sort_order' => 1,
        ];
    }
}
