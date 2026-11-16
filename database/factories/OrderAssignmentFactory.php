<?php

namespace Database\Factories;

use App\Models\OrderAssignment;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderAssignment>
 */
class OrderAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_order_id' => ServiceOrder::factory(),
            'user_id' => User::factory()->state(['role' => 'technician']),
            'assignment_role' => 'technician',
            'assigned_at' => now(),
            'released_at' => null,
        ];
    }
}
