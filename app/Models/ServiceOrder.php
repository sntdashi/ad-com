<?php

namespace App\Models;

use App\Services\Sla\SlaService;
use Database\Factories\ServiceOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $ticket_number
 * @property int|null $customer_id
 * @property string|null $customer_asset_id
 * @property int $service_id
 * @property int|null $assigned_technician_id
 * @property int|null $supervisor_id
 * @property string $customer_name
 * @property string $phone
 * @property string|null $email
 * @property string $address
 * @property string|null $district
 * @property string $complaint
 * @property string|null $preferred_window
 * @property string $urgency
 * @property string $risk_level
 * @property string $status
 * @property int|null $estimated_total
 * @property Carbon|null $preferred_date
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $first_responded_at
 * @property Carbon|null $response_due_at
 * @property Carbon|null $resolution_due_at
 * @property Carbon|null $sla_paused_at
 * @property int $sla_paused_seconds
 * @property string|null $sla_pause_reason
 * @property Carbon|null $sla_response_notified_at
 * @property Carbon|null $sla_resolution_notified_at
 * @property Carbon|null $sla_breach_notified_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'ticket_number',
    'tracking_token_hash',
    'access_code_hash',
    'customer_id',
    'customer_asset_id',
    'service_id',
    'assigned_technician_id',
    'supervisor_id',
    'customer_name',
    'phone',
    'email',
    'address',
    'district',
    'service_mode',
    'complaint',
    'preferred_date',
    'preferred_window',
    'urgency',
    'risk_level',
    'status',
    'estimated_total',
    'scheduled_at',
    'approved_at',
    'first_responded_at',
    'response_due_at',
    'resolution_due_at',
    'sla_paused_at',
    'sla_paused_seconds',
    'sla_pause_reason',
    'sla_response_notified_at',
    'sla_resolution_notified_at',
    'sla_breach_notified_at',
    'completed_at',
])]
class ServiceOrder extends Model
{
    /** @use HasFactory<ServiceOrderFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (ServiceOrder $order): void {
            $start = $order->created_at ?? now();

            if ($order->response_due_at === null || $order->resolution_due_at === null) {
                $deadlines = app(SlaService::class)->calculateDeadlines(
                    $start,
                    $order->urgency ?: 'normal',
                    $order->risk_level ?: 'unassessed',
                    (int) ($order->sla_paused_seconds ?? 0),
                );

                $order->response_due_at ??= Carbon::instance($deadlines['response_due_at']);
                $order->resolution_due_at ??= Carbon::instance($deadlines['resolution_due_at']);
            }

            if (
                in_array($order->status, SlaService::PAUSED_STATUSES, true)
                && $order->sla_paused_at === null
            ) {
                $order->sla_paused_at = Carbon::instance($start);
                $order->sla_pause_reason ??= 'Menunggu respons atau konfirmasi pelanggan';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'estimated_total' => 'integer',
            'scheduled_at' => 'datetime',
            'approved_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'sla_paused_at' => 'datetime',
            'sla_paused_seconds' => 'integer',
            'sla_response_notified_at' => 'datetime',
            'sla_resolution_notified_at' => 'datetime',
            'sla_breach_notified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<CustomerAsset, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(CustomerAsset::class, 'customer_asset_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    /** @return BelongsTo<User, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** @return HasMany<OrderAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class)
            ->orderBy('assigned_at')
            ->orderBy('id');
    }

    /** @return HasMany<OrderAssignment, $this> */
    public function activeTechnicianAssignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class)
            ->where('assignment_role', 'technician')
            ->whereNull('released_at')
            ->orderBy('assigned_at')
            ->orderBy('id');
    }

    /** @return HasMany<OrderChecklistResult, $this> */
    public function checklistResults(): HasMany
    {
        return $this->hasMany(OrderChecklistResult::class);
    }

    /** @return HasMany<OrderStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->orderBy('occurred_at');
    }

    /** @return HasMany<Estimate, $this> */
    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('created_at');
    }

    /** @return HasMany<OrderMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(OrderMessage::class)->oldest();
    }

    /** @return HasMany<TechnicianReview, $this> */
    public function technicianReviews(): HasMany
    {
        return $this->hasMany(TechnicianReview::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** @return HasMany<ServiceOrderSparePart, $this> */
    public function spareParts(): HasMany
    {
        return $this->hasMany(ServiceOrderSparePart::class)->latest('requested_at')->latest('id');
    }

    /** @return HasMany<FinancialTransaction, $this> */
    public function financialTransactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    /** @return HasMany<TechnicianWorkSession, $this> */
    public function workSessions(): HasMany
    {
        return $this->hasMany(TechnicianWorkSession::class)->latest('started_at');
    }

    /** @return HasMany<OrderWorkEvidence, $this> */
    public function workEvidences(): HasMany
    {
        return $this->hasMany(OrderWorkEvidence::class)->oldest();
    }

    /** @return HasMany<AssetServiceRecord, $this> */
    public function assetRecords(): HasMany
    {
        return $this->hasMany(AssetServiceRecord::class, 'service_order_id');
    }

    /** @return HasMany<Warranty, $this> */
    public function warranties(): HasMany
    {
        return $this->hasMany(Warranty::class, 'service_order_id')->latest('id');
    }

    /** @return HasMany<WarrantyClaim, $this> */
    public function warrantyClaims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class, 'original_service_order_id')->latest('submitted_at');
    }
}
