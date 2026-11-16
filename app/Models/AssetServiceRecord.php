<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $customer_asset_id
 * @property string $service_order_id
 * @property array<string, mixed>|null $device_snapshot
 * @property string|null $diagnosis
 * @property string|null $action_taken
 * @property string|null $test_result
 * @property string|null $recommendation
 * @property Carbon|null $warranty_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CustomerAsset $asset
 * @property-read ServiceOrder $order
 */
#[Fillable([
    'customer_asset_id',
    'service_order_id',
    'device_snapshot',
    'diagnosis',
    'action_taken',
    'test_result',
    'recommendation',
    'warranty_until',
])]
class AssetServiceRecord extends Model
{
    protected function casts(): array
    {
        return [
            'device_snapshot' => 'array',
            'warranty_until' => 'date',
        ];
    }

    /** @return BelongsTo<CustomerAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(CustomerAsset::class, 'customer_asset_id');
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }
}
