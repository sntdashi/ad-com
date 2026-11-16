<?php

namespace App\Models;

use Database\Factories\FinancialTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $service_order_id
 * @property string|null $invoice_id
 * @property int|null $actor_id
 * @property int|null $verified_by
 * @property string $type
 * @property string $category
 * @property int $amount
 * @property string $payment_method
 * @property string $status
 * @property string|null $reference
 * @property string|null $idempotency_key
 * @property string|null $description
 * @property string|null $proof_path
 * @property Carbon $occurred_at
 * @property Carbon|null $verified_at
 * @property string|null $rejection_reason
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property-read ServiceOrder|null $order
 * @property-read Invoice|null $invoice
 * @property-read User|null $actor
 * @property-read User|null $verifier
 * @property-read User|null $rejecter
 */
#[Fillable([
    'service_order_id',
    'invoice_id',
    'actor_id',
    'verified_by',
    'type',
    'category',
    'amount',
    'payment_method',
    'status',
    'reference',
    'idempotency_key',
    'description',
    'proof_path',
    'occurred_at',
    'verified_at',
    'rejection_reason',
    'rejected_by',
    'rejected_at',
])]
class FinancialTransaction extends Model
{
    /** @use HasFactory<FinancialTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
