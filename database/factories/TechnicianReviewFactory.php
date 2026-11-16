<?php

namespace Database\Factories;

use App\Models\ServiceOrder;
use App\Models\TechnicianReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicianReview>
 */
class TechnicianReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_order_id' => ServiceOrder::factory()->state(['status' => 'completed']),
            'technician_id' => User::factory()->state(['role' => 'technician']),
            'customer_id' => User::factory(),
            'rating' => fake()->numberBetween(3, 5),
            'quality_rating' => fake()->numberBetween(3, 5),
            'communication_rating' => fake()->numberBetween(3, 5),
            'punctuality_rating' => fake()->numberBetween(3, 5),
            'tags' => ['rapi', 'komunikatif'],
            'comment' => fake()->sentence(),
        ];
    }
}
