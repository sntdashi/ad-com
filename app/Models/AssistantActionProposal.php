<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted server-side action proposal awaiting explicit user confirmation.
 *
 * @property string $id
 * @property int $user_id
 * @property string $action_name
 * @property string $status
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property array<string, mixed>|null $preview_summary
 * @property string $idempotency_key
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $executed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $failure_reason
 * @property string|null $result_reference
 * @property array<string, mixed>|null $result_summary
 */
class AssistantActionProposal extends Model
{
    use HasUlids;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DENIED = 'denied';

    public const STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'action_name',
        'status',
        'payload',
        'payload_hash',
        'preview_summary',
        'idempotency_key',
        'expires_at',
        'confirmed_at',
        'executed_at',
        'cancelled_at',
        'failure_reason',
        'result_reference',
        'result_summary',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'preview_summary' => 'array',
            'result_summary' => 'array',
            'expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->status === self::STATUS_AWAITING_CONFIRMATION;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED || $this->expires_at->isPast();
    }
}
