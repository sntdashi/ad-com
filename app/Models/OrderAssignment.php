<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\OrderAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $service_order_id
 * @property int $user_id
 * @property string $assignment_role
 * @property CarbonInterface $assigned_at
 * @property CarbonInterface|null $released_at
 */
#[Fillable(['service_order_id', 'user_id', 'assignment_role', 'assigned_at', 'released_at'])]
class OrderAssignment extends Model
{
    /** @use HasFactory<OrderAssignmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
