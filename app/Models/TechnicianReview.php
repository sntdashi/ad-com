<?php

namespace App\Models;

use Database\Factories\TechnicianReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $service_order_id
 * @property int $technician_id
 * @property int|null $customer_id
 * @property int $rating
 * @property int $quality_rating
 * @property int $communication_rating
 * @property int $punctuality_rating
 * @property list<string>|null $tags
 * @property string|null $comment
 */
#[Fillable(['service_order_id', 'technician_id', 'customer_id', 'rating', 'quality_rating', 'communication_rating', 'punctuality_rating', 'tags', 'comment'])]
class TechnicianReview extends Model
{
    /** @use HasFactory<TechnicianReviewFactory> */
    use HasFactory;

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

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'quality_rating' => 'integer',
            'communication_rating' => 'integer',
            'punctuality_rating' => 'integer',
            'tags' => 'array',
        ];
    }
}
