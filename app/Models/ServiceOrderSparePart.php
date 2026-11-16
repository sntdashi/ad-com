<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $service_order_id
 * @property int $inventory_item_id
 * @property int|null $requested_by
 * @property int|null $approved_by
 * @property int|null $returned_by
 * @property int $requested_quantity
 * @property int $issued_quantity
 * @property int $returned_quantity
 * @property int $unit_cost
 * @property int $unit_price
 * @property string $status
 * @property string|null $request_notes
 * @property string|null $issue_notes
 * @property string|null $return_notes
 * @property string|null $cancellation_reason
 * @property Carbon $requested_at
 * @property Carbon|null $issued_at
 * @property Carbon|null $returned_at
 * @property Carbon|null $cancelled_at
 * @property-read ServiceOrder $order
 * @property-read InventoryItem $item
 * @property-read User|null $requester
 * @property-read User|null $approver
 * @property-read User|null $returner
 */
#[Fillable([
    'service_order_id',
    'inventory_item_id',
    'requested_by',
    'approved_by',
    'returned_by',
    'requested_quantity',
    'issued_quantity',
    'returned_quantity',
    'unit_cost',
    'unit_price',
    'status',
    'request_notes',
    'issue_notes',
    'return_notes',
    'cancellation_reason',
    'requested_at',
    'issued_at',
    'returned_at',
    'cancelled_at',
])]
class ServiceOrderSparePart extends Model
{
    protected function casts(): array
    {
        return [
            'requested_quantity' => 'integer',
            'issued_quantity' => 'integer',
            'returned_quantity' => 'integer',
            'unit_cost' => 'integer',
            'unit_price' => 'integer',
            'requested_at' => 'datetime',
            'issued_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function netIssuedQuantity(): int
    {
        return max(0, $this->issued_quantity - $this->returned_quantity);
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

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

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'spare_part_request_id');
    }

    /** @return HasOne<Warranty, $this> */
    public function warranty(): HasOne
    {
        return $this->hasOne(Warranty::class, 'service_order_spare_part_id')->latestOfMany('id');
    }
}
