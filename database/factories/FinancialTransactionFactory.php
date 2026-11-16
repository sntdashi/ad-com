<?php

namespace Database\Factories;

use App\Models\FinancialTransaction;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialTransaction>
 */
class FinancialTransactionFactory extends Factory
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
            'actor_id' => User::factory(),
            'type' => 'income',
            'category' => 'service_payment',
            'amount' => fake()->numberBetween(50000, 2000000),
            'payment_method' => 'bank_transfer',
            'status' => 'pending',
            'reference' => fake()->optional()->bothify('TRX-########'),
            'description' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
