<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $estimate_id
 * @property string $item_type
 * @property int|null $inventory_item_id
 * @property int|null $service_order_spare_part_id
 * @property string $label
 * @property numeric-string $quantity
 * @property int $unit_price
 * @property int $estimated_unit_cost
 * @property int $total
 * @property int $sort_order
 * @property-read Estimate $estimate
 * @property-read InventoryItem|null $inventoryItem
 */
#[Fillable([
    'estimate_id',
    'item_type',
    'inventory_item_id',
    'service_order_spare_part_id',
    'label',
    'quantity',
    'unit_price',
    'estimated_unit_cost',
    'total',
    'sort_order',
])]
class EstimateItem extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'estimated_unit_cost' => 'integer',
            'total' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
