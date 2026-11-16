<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'actor_id' => User::factory(),
            'type' => 'stock_in',
            'quantity' => 5,
            'balance_after' => 5,
            'unit_cost' => fake()->numberBetween(5000, 500000),
            'notes' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
