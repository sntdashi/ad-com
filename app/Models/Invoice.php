<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invoice_number
 * @property string|null $service_order_id
 * @property string|null $estimate_id
 * @property int|null $customer_id
 * @property string $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property int|null $created_by
 * @property int|null $issued_by
 * @property int|null $voided_by
 * @property string $status
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property int $subtotal
 * @property int $discount_amount
 * @property string $tax_rate
 * @property int $tax_amount
 * @property int $total_amount
 * @property int $paid_amount
 * @property array<string, mixed>|null $issued_snapshot
 * @property string|null $notes
 * @property Carbon|null $issued_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceOrder|null $order
 * @property-read Estimate|null $estimate
 * @property-read User|null $customer
 * @property-read User|null $creator
 * @property-read User|null $issuer
 * @property-read User|null $voider
 */
#[Fillable([
    'invoice_number',
    'service_order_id',
    'estimate_id',
    'customer_id',
    'customer_name',
    'customer_email',
    'customer_phone',
    'created_by',
    'issued_by',
    'voided_by',
    'status',
    'issue_date',
    'due_date',
    'subtotal',
    'discount_amount',
    'tax_rate',
    'tax_amount',
    'total_amount',
    'paid_amount',
    'issued_snapshot',
    'notes',
    'issued_at',
    'paid_at',
    'cancelled_at',
    'cancellation_reason',
])]
class Invoice extends Model
{
    use HasUlids;

    public function outstandingBalance(): int
    {
        if (in_array($this->status, ['cancelled', 'paid'], true)) {
            return 0;
        }

        return max(0, $this->total_amount - $this->paid_amount);
    }

    public function resolvedStatus(?CarbonInterface $at = null): string
    {
        if (in_array($this->status, ['draft', 'paid', 'cancelled'], true)) {
            return $this->status;
        }

        if ($this->outstandingBalance() === 0 && $this->total_amount > 0) {
            return 'paid';
        }

        $today = $at !== null ? Carbon::instance($at)->startOfDay() : now()->startOfDay();
        if (in_array($this->status, ['issued', 'partially_paid', 'overdue'], true) && $this->due_date !== null && $this->due_date->lt($today)) {
            return 'overdue';
        }

        return $this->status;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'issued_snapshot' => 'array',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class, 'estimate_id');
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
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<FinancialTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class, 'invoice_id')->latest('occurred_at');
    }
}
