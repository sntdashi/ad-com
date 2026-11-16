<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $channel
 * @property string $event_key
 * @property string|null $dedupe_key
 * @property string|null $recipient_phone
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property array<string, mixed>|null $payload_summary
 * @property CarbonInterface|null $sent_at
 * @property int|null $retried_by
 * @property CarbonInterface|null $retried_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $user
 * @property-read User|null $retrier
 */
class NotificationDelivery extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'channel',
        'event_key',
        'dedupe_key',
        'recipient_phone',
        'status',
        'attempts',
        'last_error',
        'payload_summary',
        'sent_at',
        'retried_by',
        'retried_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'payload_summary' => 'array',
            'sent_at' => 'datetime',
            'retried_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function retrier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retried_by');
    }
}
