<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $quotation_number
 * @property string $service_order_id
 * @property int|null $customer_id
 * @property int|null $created_by
 * @property int $version
 * @property string $status
 * @property int $labor_total
 * @property int $spare_parts_total
 * @property int $operational_total
 * @property int $subtotal
 * @property int $discount_amount
 * @property string $tax_rate
 * @property int $tax_amount
 * @property int $total
 * @property string|null $notes
 * @property Carbon|null $valid_until
 * @property Carbon|null $sent_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $rejected_at
 * @property int|null $rejected_by
 * @property string|null $rejection_reason
 * @property Carbon|null $converted_at
 * @property array<string, mixed>|null $approved_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceOrder $order
 * @property-read User|null $customer
 * @property-read User|null $creator
 * @property-read User|null $approver
 * @property-read User|null $rejecter
 * @property-read Invoice|null $invoice
 */
#[Fillable([
    'quotation_number',
    'service_order_id',
    'customer_id',
    'created_by',
    'version',
    'status',
    'labor_total',
    'spare_parts_total',
    'operational_total',
    'subtotal',
    'discount_amount',
    'tax_rate',
    'tax_amount',
    'total',
    'notes',
    'valid_until',
    'sent_at',
    'approved_at',
    'approved_by',
    'rejected_at',
    'rejected_by',
    'rejection_reason',
    'converted_at',
    'approved_snapshot',
])]
class Estimate extends Model
{
    use HasUlids;

    /**
     * Derive authoritative server-side quotation status (accounts for expiration).
     */
    public function resolvedStatus(?CarbonInterface $at = null): string
    {
        if (in_array($this->status, ['approved', 'rejected', 'converted', 'expired'], true)) {
            return $this->status;
        }

        $today = $at !== null ? Carbon::instance($at)->startOfDay() : now()->startOfDay();
        if ($this->status === 'sent' && $this->valid_until !== null && $this->valid_until->lt($today)) {
            return 'expired';
        }

        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'labor_total' => 'integer',
            'spare_parts_total' => 'integer',
            'operational_total' => 'integer',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'converted_at' => 'datetime',
            'approved_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** @return HasMany<EstimateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('sort_order');
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'estimate_id');
    }
}
