<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $invoice_id
 * @property string $item_type
 * @property string $label
 * @property string|null $description
 * @property numeric-string $quantity
 * @property int $unit_price
 * @property int $unit_cost
 * @property int $line_total
 * @property int|null $inventory_item_id
 * @property int|null $service_order_spare_part_id
 * @property int $sort_order
 * @property-read Invoice $invoice
 * @property-read InventoryItem|null $inventoryItem
 */
#[Fillable([
    'invoice_id',
    'item_type',
    'label',
    'description',
    'quantity',
    'unit_price',
    'unit_cost',
    'line_total',
    'inventory_item_id',
    'service_order_spare_part_id',
    'sort_order',
])]
class InvoiceItem extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'unit_cost' => 'integer',
            'line_total' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
