<?php

namespace App\Models;

use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $sku
 * @property string $name
 * @property string $category
 * @property string|null $description
 * @property string $unit
 * @property int $quantity_on_hand
 * @property int $reorder_level
 * @property int|null $warranty_days
 * @property string|null $warranty_terms
 * @property int $unit_cost
 * @property int|null $selling_price
 * @property string|null $storage_location
 * @property string|null $supplier_name
 * @property string|null $supplier_contact
 * @property bool $is_active
 * @property Carbon|null $low_stock_alerted_at
 */
#[Fillable([
    'sku',
    'name',
    'category',
    'description',
    'unit',
    'quantity_on_hand',
    'reorder_level',
    'warranty_days',
    'warranty_terms',
    'unit_cost',
    'selling_price',
    'storage_location',
    'supplier_name',
    'supplier_contact',
    'is_active',
    'low_stock_alerted_at',
])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'integer',
            'reorder_level' => 'integer',
            'warranty_days' => 'integer',
            'unit_cost' => 'integer',
            'selling_price' => 'integer',
            'is_active' => 'boolean',
            'low_stock_alerted_at' => 'datetime',
        ];
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** @return HasMany<ServiceOrderSparePart, $this> */
    public function sparePartRequests(): HasMany
    {
        return $this->hasMany(ServiceOrderSparePart::class);
    }
}
