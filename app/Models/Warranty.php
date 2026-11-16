<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $warranty_number
 * @property int|null $customer_id
 * @property string|null $customer_asset_id
 * @property string $service_order_id
 * @property int|null $service_order_spare_part_id
 * @property string $scope_type
 * @property string $item_label
 * @property string|null $reference_number
 * @property int $duration_days
 * @property string|null $coverage_description
 * @property string $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property int|null $activated_by
 * @property int|null $voided_by
 * @property Carbon|null $activated_notified_at
 * @property Carbon|null $expiring_notified_at
 * @property Carbon|null $expired_notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $customer
 * @property-read CustomerAsset|null $asset
 * @property-read ServiceOrder $serviceOrder
 * @property-read ServiceOrderSparePart|null $sparePart
 * @property-read User|null $activatedBy
 * @property-read User|null $voidedBy
 */
#[Fillable([
    'warranty_number',
    'customer_id',
    'customer_asset_id',
    'service_order_id',
    'service_order_spare_part_id',
    'scope_type',
    'item_label',
    'reference_number',
    'duration_days',
    'coverage_description',
    'status',
    'starts_at',
    'expires_at',
    'voided_at',
    'void_reason',
    'activated_by',
    'voided_by',
    'activated_notified_at',
    'expiring_notified_at',
    'expired_notified_at',
])]
class Warranty extends Model
{
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'voided_at' => 'datetime',
            'activated_notified_at' => 'datetime',
            'expiring_notified_at' => 'datetime',
            'expired_notified_at' => 'datetime',
        ];
    }

    /**
     * Derive the authoritative server-side warranty status at the given point in time.
     */
    public function resolvedStatus(?CarbonInterface $at = null): string
    {
        if ($this->status === 'void' || $this->voided_at !== null) {
            return 'void';
        }

        if ($this->status === 'expired') {
            return 'expired';
        }

        if ($this->status === 'pending' && $this->starts_at === null) {
            return 'pending';
        }

        $now = $at !== null ? Carbon::instance($at) : now();

        if ($this->starts_at !== null && $this->starts_at->greaterThan($now)) {
            return 'pending';
        }

        if ($this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($now)) {
            return 'expired';
        }

        return 'active';
    }

    public function isApproachingExpiration(int $windowDays = 7, ?CarbonInterface $at = null): bool
    {
        if ($this->resolvedStatus($at) !== 'active' || $this->expires_at === null) {
            return false;
        }

        $now = $at !== null ? Carbon::instance($at) : now();

        return $this->expires_at->greaterThan($now)
            && $this->expires_at->lessThanOrEqualTo($now->copy()->addDays($windowDays));
    }

    public function remainingDays(?CarbonInterface $at = null): ?int
    {
        if ($this->expires_at === null || $this->resolvedStatus($at) === 'void') {
            return null;
        }

        $now = $at !== null ? Carbon::instance($at) : now();

        return (int) floor($now->diffInHours($this->expires_at, false) / 24);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<CustomerAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(CustomerAsset::class, 'customer_asset_id');
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    /** @return BelongsTo<ServiceOrderSparePart, $this> */
    public function sparePart(): BelongsTo
    {
        return $this->belongsTo(ServiceOrderSparePart::class, 'service_order_spare_part_id');
    }

    /** @return BelongsTo<User, $this> */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @return HasMany<WarrantyClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class, 'warranty_id')->latest('submitted_at');
    }
}
