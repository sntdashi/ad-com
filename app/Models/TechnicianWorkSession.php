<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $service_order_id
 * @property int $technician_id
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $resumed_at
 * @property int $paused_seconds
 * @property Carbon|null $completed_at
 * @property int $duration_seconds
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'service_order_id',
    'technician_id',
    'status',
    'started_at',
    'paused_at',
    'resumed_at',
    'paused_seconds',
    'completed_at',
    'duration_seconds',
    'notes',
])]
class TechnicianWorkSession extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
            'paused_seconds' => 'integer',
            'completed_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
