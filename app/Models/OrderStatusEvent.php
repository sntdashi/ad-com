<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $service_order_id
 * @property int|null $actor_id
 * @property string $status
 * @property string $title
 * @property string|null $description
 * @property string $visibility
 * @property Carbon $occurred_at
 */
#[Fillable(['service_order_id', 'actor_id', 'status', 'title', 'description', 'visibility', 'occurred_at'])]
class OrderStatusEvent extends Model
{
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /** @return BelongsTo<ServiceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
