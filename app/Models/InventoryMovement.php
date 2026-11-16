<?php

namespace App\Models;

use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $inventory_item_id
 * @property string|null $service_order_id
 * @property int|null $spare_part_request_id
 * @property int|null $actor_id
 * @property int|null $financial_transaction_id
 * @property string $type
 * @property int $quantity
 * @property int|null $balance_before
 * @property int $balance_after
 * @property int|null $unit_cost
 * @property string|null $reference
 * @property string|null $supplier_name
 * @property string|null $adjustment_reason
 * @property string|null $notes
 * @property Carbon $occurred_at
 * @property-read InventoryItem $item
 * @property-read ServiceOrder|null $order
 * @property-read ServiceOrderSparePart|null $sparePartRequest
 * @property-read User|null $actor
 * @property-read FinancialTransaction|null $financialTransaction
 */
#[Fillable([
    'inventory_item_id',
    'service_order_id',
    'spare_part_request_id',
    'actor_id',
    'financial_transaction_id',
    'type',
    'quantity',
    'balance_before',
    'balance_after',
    'unit_cost',
    'reference',
    'supplier_name',
    'adjustment_reason',
    'notes',
    'occurred_at',
])]
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'unit_cost' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    /** @return BelongsTo<ServiceOrderSparePart, $this> */
    public function sparePartRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceOrderSparePart::class, 'spare_part_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<FinancialTransaction, $this> */
    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'financial_transaction_id');
    }
}
