<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_number
 * @property int $inventory_item_id
 * @property int $requested_by
 * @property int $requested_quantity
 * @property int $estimated_total_cost
 * @property string $priority
 * @property string $status
 * @property string|null $supplier_name
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'request_number',
    'inventory_item_id',
    'requested_by',
    'requested_quantity',
    'estimated_total_cost',
    'priority',
    'status',
    'supplier_name',
    'notes',
])]
class InventoryRestockRequest extends Model
{
    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
