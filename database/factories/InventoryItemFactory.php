<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('AD-??-####'),
            'name' => fake()->words(3, true),
            'category' => fake()->randomElement(['Jaringan', 'CCTV', 'Komputer', 'Consumable']),
            'unit' => fake()->randomElement(['unit', 'meter', 'box']),
            'quantity_on_hand' => fake()->numberBetween(0, 40),
            'reorder_level' => fake()->numberBetween(2, 8),
            'unit_cost' => fake()->numberBetween(5000, 500000),
            'storage_location' => fake()->randomElement(['Rak A1', 'Rak B2', 'Lemari teknisi']),
            'is_active' => true,
        ];
    }
}
